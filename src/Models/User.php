<?php

namespace UserAuth\Models;

use Phalcon\Mvc\Model;
use Phalcon\Mvc\Model\Relation;
use Phalcon\Mvc\Model\Transaction\Failed as TransactionFailed;
use Phalcon\Mvc\Model\Transaction\Manager as TransactionManager;
use Phalcon\Mvc\Model\Validator\Email as EmailValidator;
use Phalcon\Mvc\Model\Validator\Uniqueness as UniquenessValidator;
use UserAuth\Exceptions\StatusChangeException;
use UserAuth\Exceptions\UserAuthenticationException;
use UserAuth\Exceptions\PasswordChangeException;
use UserAuth\Exceptions\UserCreationException;
use UserAuth\Libraries\Utils;


/**
 * Class User
 * @author Tega Oghenekohwo <tega@cottacush.com>
 * @package UserAuth\models
 */
class User extends Model
{
    /**
     * Constant to show that a user's account is not yet active (status after registration)
     */
    const STATUS_INACTIVE = 0;

    /**
     * Constant to show that a user's account is active
     */
    const STATUS_ACTIVE = 1;

    /**
     * Constant to show that a user's account has been suspended/disabled
     * This is a status that is triggered by an administrator
     */
    const STATUS_DISABLED = 2;

    static $statusMap = [
        self::STATUS_INACTIVE => 'Inactive',
        self::STATUS_ACTIVE => 'Active',
        self::STATUS_DISABLED => 'Disabled'
    ];

    /**
     * @var integer
     */
    protected $id;

    /**
     * @var string
     */
    protected $email;

    /**
     * @var string
     */
    protected $password;

    /**
     * @var string
     */
    protected $created_at;

    /**
     * @var string
     */
    protected $updated_at;

    /**
     * @var integer
     */
    protected $status;


    /**
     * Returns the name of the table that holds the user's information
     * @return string
     */
    public function getSource()
    {
        return "user_credentials";
    }

    /**
     * Validate user's email
     * @return bool
     */
    public function validation()
    {
        $this->validate(new EmailValidator([
            'field' => 'email',
            'message' => 'Invalid email supplied'
        ]));

        $this->validate(new UniquenessValidator(array(
            'field' => 'email',
            'message' => 'Sorry, The email has been used by another user'
        )));


        if ($this->validationHasFailed() == true) {
            return false;
        }

        return true;
    }


    /**
     * Define links/relationship with other tables
     */
    public function initialize()
    {
        $this->hasMany('id', 'UserAuth\Models\UserPasswordChange', 'user_id', [
            'alias' => 'PasswordChanges',
            'foreignKey' => [
                'action' => Relation::ACTION_CASCADE,
            ],
        ]);

        $this->hasMany('id', 'UserAuth\Models\UserPasswordReset', 'user_id', [
            'alias' => 'PasswordResets',
            'foreignKey' => [
                'action' => Relation::ACTION_CASCADE,
            ],
        ]);
    }


    /**
     * Encrypt user password before creating user
     */
    public function beforeCreate()
    {
        $this->password = Utils::encryptPassword($this->password);
    }

    /**
     * Set updated at date and time before updating a user
     */
    public function beforeUpdate()
    {
        $this->updated_at = date("Y-m-d H:i:s");
    }

    /**
     * @param string $email
     * @param string $password
     * @param bool|false $setActive
     * @return int the ID of the created user
     * @throws UserCreationException
     */
    public function createUser($email, $password, $setActive = false)
    {
        $this->email = $email;
        $this->password = $password;
        $this->created_at = date("Y-m-d H:i:s");
        $this->status = $setActive ? self::STATUS_ACTIVE : self::STATUS_INACTIVE;
        if (!$this->create()) {
            throw new UserCreationException($this->getMessages());
        }
        return $this->id;
    }

    /**
     * Generate random password
     *
     * @param int $length
     * @param bool|true $strict (if set to true, a symbol will be added to the password)
     * @return string
     */
    public static function generateRandomPassword($length = 8, $strict = true)
    {
        return Utils::generateRandomPassword($length, $strict);
    }

    /**
     * @param string $email
     * @param string $password
     * @return bool
     * @throws UserAuthenticationException
     */
    public function authenticate($email, $password)
    {
        $user = User::findFirst([
            "email = :email:",
            'bind' => ['email' => $email]
        ]);

        if ($user == false) {
            throw new UserAuthenticationException(ErrorMessages::INVALID_AUTHENTICATION_DETAILS);
        }

        //validate password
        if (!Utils::verifyPassword($password, $user->password)) {
            throw new UserAuthenticationException(ErrorMessages::INVALID_AUTHENTICATION_DETAILS);
        }

        $this->validateStatus($user->status);

        return true;
    }

    /**
     * @param string $email
     * @param string $previousPassword
     * @param string $newPassword
     * @param int $max the maximum number of changes before a password can be re-used
     * @return bool
     * @throws UserAuthenticationException
     * @throws PasswordChangeException
     */
    public function changePassword($email, $previousPassword, $newPassword, $max = UserPasswordChange::MAX_PASSWORD_CHANGES_BEFORE_REUSE)
    {
        //first check that the user exists
        $user = User::findFirst([
            "email = :email:",
            'bind' => ['email' => $email]
        ]);

        if ($user == false) {
            throw new PasswordChangeException(ErrorMessages::EMAIL_DOES_NOT_EXIST);
        }
        $this->id = $user->id;

        //validate password
        if (!Utils::verifyPassword($previousPassword, $user->password)) {
            throw new PasswordChangeException(ErrorMessages::OLD_PASSWORD_INVALID);
        }

        //check if new password matches user's current password
        if (Utils::verifyPassword($newPassword, $user->password)) {
            throw new PasswordChangeException("You cannot use any of your last {$max} passwords");
        }

        //check user's status
        $this->validateStatus($user->status);

        //Validate new password
        UserPasswordChange::validateNewPassword($this->id, $newPassword, $max);

        //if all goes well, proceed to update the password
        return $this->updatePassword($previousPassword, $newPassword);
    }

    /**
     * @param string $previousPassword
     * @param string $newPassword
     * @return bool
     * @throws PasswordChangeException
     */
    public function updatePassword($previousPassword, $newPassword)
    {
        $transactionManager = new TransactionManager();
        try {
            //use a transaction as we would be updating more than one table
            $transaction = $transactionManager->get();
            $this->setTransaction($transaction);
            $user = User::findFirst($this->id);
            $user->password = Utils::encryptPassword($newPassword);
            if (!$user->save()) {
                $transaction->rollback(ErrorMessages::PASSWORD_UPDATE_FAILED);
            }

            $userPasswordChange = new UserPasswordChange();
            $userPasswordChange->setTransaction($transaction);
            $userPasswordChange->setDateChanged(date("Y-m-d H:i:s"));
            $userPasswordChange->setUserId($this->id);
            $userPasswordChange->setPasswordHash(Utils::encryptPassword($previousPassword));
            if (!$userPasswordChange->save()) {
                $transaction->rollback(ErrorMessages::PASSWORD_UPDATE_FAILED);
            }

            $transaction->commit();
            return true;
        } catch (TransactionFailed $e) {
            throw new PasswordChangeException($e->getMessage());
        }
    }

    /**
     * Set a user's account status to active
     * @param string $email
     * @return bool
     * @throws StatusChangeException
     */
    public function setActive($email)
    {
        return $this->changeUserStatus($email, self::STATUS_ACTIVE);
    }

    /**
     * Set a user's account status to inactive
     * @param string $email
     * @return bool
     * @throws StatusChangeException
     */
    public function setInactive($email)
    {
        return $this->changeUserStatus($email, self::STATUS_INACTIVE);
    }

    /**
     * Set a user's account status to disabled
     * @param string $email
     * @return bool
     * @throws StatusChangeException
     */
    public function disableUser($email)
    {
        return $this->changeUserStatus($email, self::STATUS_DISABLED);
    }

    /**
     * Change a user's status
     * @param string $email
     * @param int $newStatus
     * @return bool
     * @throws StatusChangeException
     */
    public function changeUserStatus($email, $newStatus)
    {
        $user = $this->getUserByEmail($email);
        if (empty($user)) {
            throw new StatusChangeException(ErrorMessages::EMAIL_DOES_NOT_EXIST);
        }

        if (!array_key_exists($newStatus, self::$statusMap)) {
            throw new StatusChangeException(ErrorMessages::INVALID_STATUS);
        }

        if ($user->status == $newStatus) {
            throw new StatusChangeException("User status is already set to " . self::getStatusDescriptionFromCode($newStatus));
        }

        //all is fine
        $user = User::findFirst($this->id);
        $user->status = (int) $newStatus;
        if (!$user->save()) {
            throw new StatusChangeException(ErrorMessages::STATUS_UPDATE_FAILED);
        }

        return true;
    }

    /**
     * Get user details
     * @param string $email
     * @return Model
     */

    public function getUserByEmail($email)
    {
        return User::findFirst([
            "email = :email:",
            'bind' => ['email' => $email]
        ]);
    }

    /**
     * Get a meaningful textual description of a user's status using the status code
     * @param $statusCode
     * @return string
     */
    public static function getStatusDescriptionFromCode($statusCode)
    {
        if (empty(self::$statusMap[$statusCode])) {
            return "Unknown";
        }

        return self::$statusMap[$statusCode];
    }


    public function generateResetPasswordToken($email, $tokenLength = UserPasswordReset::DEFAULT_TOKEN_LENGTH)
    {

    }

    /**
     * Validates a user's status before taking further actions e.g login, password change, password reset
     * @param string $status
     * @throws UserAuthenticationException
     */
    public function validateStatus($status)
    {
        if ($status == self::STATUS_INACTIVE) {
            throw new UserAuthenticationException(ErrorMessages::INACTIVE_ACCOUNT);
        }

        if ($status == self::STATUS_DISABLED) {
            throw new UserAuthenticationException(ErrorMessages::DISABLED_ACCOUNT);
        }
    }

}