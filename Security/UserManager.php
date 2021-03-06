<?php

namespace AC\LoginConvenienceBundle\Security;

use Fp\OpenIdBundle\Model\UserManager as BaseUserManager;
use Fp\OpenIdBundle\Model\IdentityManagerInterface;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\User\UserProviderInterface;

class UserManager extends BaseUserManager
{
    private $userProvider;
    private $trustedProvider;
    private $apiKeyMap;

    public function __construct(
    IdentityManagerInterface $identityManager,
    UserProviderInterface $userProvider,
    $trustedProviders,
    $apiKeyMap
    ) {
        parent::__construct($identityManager);

        $this->userProvider = $userProvider;
        $this->trustedProviders = $trustedProviders;
        $this->apiKeyMap = $apiKeyMap;
    }

    // This is somewhat misnamed; we aren't going to create a user from an
    // identity, but we may persist this identity and associate it with an
    // existing user if we trust the provider.
    public function createUserFromIdentity($identity, array $attributes = array())
    {
        $trusted = false;
        foreach ($this->trustedProviders as $provider) {
            if (strpos($identity, $provider) === 0) {
                $trusted = true;
                break;
            }
        }
        if (!$trusted) {
            throw new BadCredentialsException("Untrusted identity: $identity");
        }

        if (!isset($attributes['contact/email'])) {
            throw new BadCredentialsException('No email address provided');
        }
        $email = $attributes['contact/email'];

        $user = null;
        try {
            $user = $this->loadUserByInternalUsername($email);
        } catch (UsernameNotFoundException $e) {
            // Leave $user as null
        }
        if (!$user) {
            throw new BadCredentialsException('User not known to application');
        }

        $this->associateIdentityWithUser($identity, $user, $attributes);

        return $user;
    }

    public function loadUserByInternalUsername($username)
    {
        return $this->userProvider->loadUserByUsername($username);
    }

    public function associateIdentityWithUser($identity, $user, $attributes = array())
    {
        $openIdIdentity = $this->identityManager->create();
        $openIdIdentity->setIdentity($identity);
        $openIdIdentity->setAttributes($attributes);
        $openIdIdentity->setUser($user);
        $this->identityManager->update($openIdIdentity);
    }

    public function getUsernameForApiKey($key)
    {
        if (!is_array($this->apiKeyMap)) { return null; }

        foreach ($this->apiKeyMap as $username => $userKey) {
            if ($key == $userKey) { return $username; }
        }
        return null;
    }
}
