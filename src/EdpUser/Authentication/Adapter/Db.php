<?php

namespace EdpUser\Authentication\Adapter;

use EdpUser\Authentication\Adapter\AdapterChainEvent as AuthEvent,
    Zend\Authentication\Result as AuthenticationResult,
    EdpUser\Module as EdpUser,
    EdpUser\Mapper\UserInterface as UserMapper,
    EdpUser\Util\Password,
    DateTime;

class Db extends AbstractAdapter
{
    /**
     * @var UserMapper
     */
    protected $mapper;

    public function authenticate(AuthEvent $e)
    {
        if ($this->isSatisfied()) {
            $storage = $this->getStorage()->read();
            $e->setIdentity($storage['identity'])
              ->setCode(AuthenticationResult::SUCCESS)
              ->setMessages(array('Authentication successful.'));
            return;
        }

        $identity   = $e->getRequest()->post()->get('identity');
        $credential = $e->getRequest()->post()->get('credential');

        $userObject = $this->getMapper()->findByEmail($identity);

        if (!$userObject && EdpUser::getOption('enable_username')) {
            // Auth by username
            $userObject = $this->getMapper()->findByUsername($identity);
        }
        if (!$userObject) {
            $e->setCode(AuthenticationResult::FAILURE_IDENTITY_NOT_FOUND)
              ->setMessages(array('A record with the supplied identity could not be found.'));
            $this->setSatisfied(false);
            return false;
        }

        $credentialHash = Password::hash($credential, $userObject->getPassword());

        if ($credentialHash !== $userObject->getPassword()) {
            // Password does not match
            $e->setCode(AuthenticationResult::FAILURE_CREDENTIAL_INVALID)
              ->setMessages(array('Supplied credential is invalid.'));
            $this->setSatisfied(false);
            return false;
        }

        // Success!
        $e->setIdentity($userObject->getUserId());
        $this->updateUserPasswordHash($userObject, $credential)
             ->updateUserLastLogin($userObject)
             ->setSatisfied(true);
        $storage = $this->getStorage()->read();
        $storage['identity'] = $e->getIdentity();
        $this->getStorage()->write($storage);
        $e->setCode(AuthenticationResult::SUCCESS)
          ->setMessages(array('Authentication successful.'));
    }

    /**
     * getMapper 
     * 
     * @return UserMapper
     */
    public function getMapper()
    {
        return $this->mapper;
    }

    /**
     * setMapper 
     * 
     * @param UserMapper $mapper 
     * @return Db
     */
    public function setMapper(UserMapper $mapper)
    {
        $this->mapper = $mapper;
        return $this;
    }

    protected function updateUserPasswordHash($userObject, $password)
    {
        $newHash = Password::hash($password);
        if ($newHash === $userObject->getPassword()) return $this;

        $userObject->setPassword($newHash);

        $this->getMapper()->persist($userObject);
        return $this;
    }

    protected function updateUserLastLogin($userObject)
    {
        $userObject->setLastLogin(new DateTime('now'))
                   ->setLastIp($_SERVER['REMOTE_ADDR']);

        $this->getMapper()->persist($userObject);
        return $this;
    }
}
