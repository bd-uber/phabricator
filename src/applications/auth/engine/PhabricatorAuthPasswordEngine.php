<?php

final class PhabricatorAuthPasswordEngine
  extends Phobject {

  private $viewer;
  private $contentSource;
  private $object;
  private $passwordType;
  private $upgradeHashers = true;

  public function setViewer(PhabricatorUser $viewer) {
    $this->viewer = $viewer;
    return $this;
  }

  public function getViewer() {
    return $this->viewer;
  }

  public function setContentSource(PhabricatorContentSource $content_source) {
    $this->contentSource = $content_source;
    return $this;
  }

  public function getContentSource() {
    return $this->contentSource;
  }

  public function setObject(PhabricatorPasswordHashInterface $object) {
    $this->object = $object;
    return $this;
  }

  public function getObject() {
    return $this->object;
  }

  public function setPasswordType($password_type) {
    $this->passwordType = $password_type;
    return $this;
  }

  public function getPasswordType() {
    return $this->passwordType;
  }

  public function setUpgradeHashers($upgrade_hashers) {
    $this->upgradeHashers = $upgrade_hashers;
    return $this;
  }

  public function getUpgradeHashers() {
    return $this->upgradeHashers;
  }

  public function isValidPassword(PhutilOpaqueEnvelope $envelope) {
    $this->requireSetup();

    $password_type = $this->getPasswordType();

    $passwords = $this->newQuery()
      ->withPasswordTypes(array($password_type))
      ->withIsRevoked(false)
      ->execute();

    $matches = $this->getMatches($envelope, $passwords);
    if (!$matches) {
      return false;
    }

    if ($this->shouldUpgradeHashers()) {
      $this->upgradeHashers($envelope, $matches);
    }

    return true;
  }

  public function isUniquePassword(PhutilOpaqueEnvelope $envelope) {
    $this->requireSetup();

    $password_type = $this->getPasswordType();

    // To test that the password is unique, we're loading all active and
    // revoked passwords for all roles for the given user, then throwing out
    // the active passwords for the current role (so a password can't
    // collide with itself).

    // Note that two different objects can have the same password (say,
    // users @alice and @bailey). We're only preventing @alice from using
    // the same password for everything.

    $passwords = $this->newQuery()
      ->execute();

    foreach ($passwords as $key => $password) {
      $same_type = ($password->getPasswordType() === $password_type);
      $is_active = !$password->getIsRevoked();

      if ($same_type && $is_active) {
        unset($passwords[$key]);
      }
    }

    $matches = $this->getMatches($envelope, $passwords);

    return !$matches;
  }

  public function isRevokedPassword(PhutilOpaqueEnvelope $envelope) {
    $this->requireSetup();

    // To test if a password is revoked, we're loading all revoked passwords
    // across all roles for the given user. If a password was revoked in one
    // role, you can't reuse it in a different role.

    $passwords = $this->newQuery()
      ->withIsRevoked(true)
      ->execute();

    $matches = $this->getMatches($envelope, $passwords);

    return (bool)$matches;
  }

  private function requireSetup() {
    if (!$this->getObject()) {
      throw new PhutilInvalidStateException('setObject');
    }

    if (!$this->getPasswordType()) {
      throw new PhutilInvalidStateException('setPasswordType');
    }

    if (!$this->getViewer()) {
      throw new PhutilInvalidStateException('setViewer');
    }

    if ($this->shouldUpgradeHashers()) {
      if (!$this->getContentSource()) {
        throw new PhutilInvalidStateException('setContentSource');
      }
    }
  }

  private function shouldUpgradeHashers() {
    if (!$this->getUpgradeHashers()) {
      return false;
    }

    if (PhabricatorEnv::isReadOnly()) {
      // Don't try to upgrade hashers if we're in read-only mode, since we
      // won't be able to write the new hash to the database.
      return false;
    }

    return true;
  }

  private function newQuery() {
    $viewer = $this->getViewer();
    $object = $this->getObject();
    $password_type = $this->getPasswordType();

    return id(new PhabricatorAuthPasswordQuery())
      ->setViewer($viewer)
      ->withObjectPHIDs(array($object->getPHID()));
  }

  private function getMatches(
    PhutilOpaqueEnvelope $envelope,
    array $passwords) {

    $object = $this->getObject();

    $matches = array();
    foreach ($passwords as $password) {
      try {
        $is_match = $password->comparePassword($envelope, $object);
      } catch (PhabricatorPasswordHasherUnavailableException $ex) {
        $is_match = false;
      }

      if ($is_match) {
        $matches[] = $password;
      }
    }

    return $matches;
  }

  private function upgradeHashers(
    PhutilOpaqueEnvelope $envelope,
    array $passwords) {

    assert_instances_of($passwords, 'PhabricatorAuthPassword');

    $need_upgrade = array();
    foreach ($passwords as $password) {
      if (!$password->canUpgrade()) {
        continue;
      }
      $need_upgrade[] = $password;
    }

    if (!$need_upgrade) {
      return;
    }

    $upgrade_type = PhabricatorAuthPasswordUpgradeTransaction::TRANSACTIONTYPE;
    $viewer = $this->getViewer();
    $content_source = $this->getContentSource();

    $unguarded = AphrontWriteGuard::beginScopedUnguardedWrites();
    foreach ($need_upgrade as $password) {

      // This does the actual upgrade. We then apply a transaction to make
      // the upgrade more visible and auditable.
      $old_hasher = $password->getHasher();
      $password->upgradePasswordHasher($envelope, $this->getObject());
      $new_hasher = $password->getHasher();

      $xactions = array();

      $xactions[] = $password->getApplicationTransactionTemplate()
        ->setTransactionType($upgrade_type)
        ->setNewValue($new_hasher->getHashName());

      $editor = $password->getApplicationTransactionEditor()
        ->setActor($viewer)
        ->setContinueOnNoEffect(true)
        ->setContinueOnMissingFields(true)
        ->setContentSource($content_source)
        ->setOldHasher($old_hasher)
        ->applyTransactions($password, $xactions);
    }
    unset($unguarded);
  }

}
