<?php

namespace Models\src\Services;

use Models\Exceptions\FormException;
use Models\src\Brokers\UserBroker;
use Models\src\Entities\User;
use Models\src\Validators\AuthValidator;
use Zephyrus\Application\Form;

class AuthService extends BaseService
{
    private EncryptionService $encryptionService;

    public function __construct()
    {
        $this->userBroker = new UserBroker();
        $this->encryptionService = new EncryptionService();
    }

    public function register(Form $form, $isHtmx): array
    {
        try {
            AuthValidator::assertRegister($form, $this->userBroker, $isHtmx);

            if ($isHtmx) {
                return [
                    "form" => $form,
                    "status" => 200
                ];
            }

            $password = $form->getValue("password");
            $salt = $this->encryptionService->generateSalt();
            $userKey = $this->encryptionService->deriveUserKey($password, $salt);
            $hashedPassword = $this->encryptionService->hashPassword($password);

            $encryptedData = $this->buildEncryptedUserData($form, $hashedPassword, $salt, $userKey);
            $user = $this->userBroker->createUser($encryptedData);

            $this->encryptionService->storeUserContext($user->id, $userKey);

            return $this->buildSuccessRegisterResponse($user, $form);
        } catch (FormException) {
            return $this->buildErrorResponse($form);
        }
    }

    public function login(Form $form, $isHtmx): array
    {
        try {
            AuthValidator::assertLogin($form, $isHtmx);

            $email = $form->getValue("email");
            $password = $form->getValue("password");

            $user = $this->validateUserCredentials($email, $password, $form);
            $userKey = $this->encryptionService->deriveUserKey($password, $user->salt);

            $user = $this->userBroker->findByEmail($email, $userKey);

            if ($isHtmx) {
                return [
                    "form" => $form,
                    "status" => 200
                ];
            }

            $this->encryptionService->storeUserContext($user->id, $userKey);

            return $this->buildSuccessLoginResponse($user);
        } catch (FormException) {
            return $this->buildErrorResponse($form);
        }
    }

    // Helpers

    private function validateUserCredentials(string $email, string $password, Form $form): User
    {
        $user = $this->userBroker->findByEmail($email);

        if (!$user || !$this->encryptionService->verifyPassword($password, $user->password_hash)) {
            $form->addError("login", "Identifiants invalides.");
            throw new FormException($form);
        }

        return $user;
    }

    private function buildEncryptedUserData(Form $form, string $hashedPassword, string $salt, string $userKey): array
    {
        return [
            'first_name'    => $this->encryptionService->encryptWithUserKey($form->getValue("first_name"), $userKey),
            'last_name'     => $this->encryptionService->encryptWithUserKey($form->getValue("last_name"), $userKey),
            'email'         => $this->encryptionService->encryptWithUserKey($form->getValue("email"), $userKey),
            'phone'         => $this->encryptionService->encryptWithUserKey($form->getValue("phone"), $userKey),
            'image_url'     => $this->encryptionService->encryptWithUserKey($form->getValue("image_url") ?? "", $userKey),
            'email_hash'    => $this->encryptionService->hash256($form->getValue("email")),
            'password_hash' => $hashedPassword,
            'salt'          => $salt
        ];
    }

    private function buildSuccessRegisterResponse(User $user, Form $form): array
    {
        return [
            "message" => "Compte créé avec succès",
            "user" => [
                "id"        => $user->id,
                "email"     => $form->getValue("email"),
                "firstName" => $form->getValue("first_name"),
                "lastName"  => $form->getValue("last_name")
            ],
            "status" => 201
        ];
    }

    private function buildSuccessLoginResponse(User $user): array
    {
        return [
            "message" => "Connexion réussie",
            "user" => [
                "id"    => $user->id,
                "email" => $user->email
            ],
            "status" => 200
        ];
    }
}
