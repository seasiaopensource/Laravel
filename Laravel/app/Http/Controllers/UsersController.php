<?php

namespace App\Http\Controllers;

use App\Notification;
use App\Services\SeedToElastic;
use Mail;
use Hash;
use Crypt;
use Event;
use JWTAuth;
use App\User;
use App\Customer;
use App\Addressbook;
use App\Object2Object;
use App\Productschema;
use App\Http\Requests;
use App\Productprofile;
use App\ObjectPermission;
use App\Permission;
use App\Folder;
use App\Folderschema;
use App\Attachment;
use Illuminate\Http\Request;
use App\Exceptions\APIException;
use App\Events\UserValidatedEvent;
use App\Events\UserRegisteredEvent;
use Illuminate\Support\Facades\Input;
use App\Http\Helpers\PaginationHelper;
use App\Events\UserForgotPasswordEvent;
use App\Events\UserChangePasswordEvent;
use App\Events\UserChangePasswordValidatedEvent;


class UsersController extends AppController
{

    public function __construct(Request $request)
    {
        $this->setAuthCallback(function () {
            // Apply the jwt.auth middleware to all methods in this controller
            // except for the authenticate method. We don't want to prevent
            // the user from retrieving their token if they don't already have it
            $this->middleware('jwt.auth', ['except' => ['register', 'validation', 'forgotPasswort', 'validateToken', 'passwordReset', 'updatePassword']]);
            return true;
        });
        parent::__construct($request);
    }


    /**
     * @api {get} /users Retrieve userlist
     * @apiVersion 0.1.0
     * @apiName GetUsers
     * @apiGroup User
     * @apiPermission user
     *
     * @apiDescription Retrieve a List of users
     *
     * @apiSuccess {Object[]}  users User
     * @apiUse UserReturnSuccess
     */
    public function index(Request $request)
    {
        return PaginationHelper::execPagination(
            'users',
            User::where('customer_id', User::current()->customer_id),
            $request
        );
    }

    /**
     * @api {get} /users/:id Retrieve User details
     * @apiVersion 0.1.0
     * @apiName GetUser
     * @apiGroup User
     * @apiPermission user
     *
     * @apiDescription Retrieve User Details -
     * Use this endpoint to recieve all details of an user
     *
     * @apiSuccess {Object[]}  User               User
     * @apiUse UserReturnSuccess
     *
     * @apiUse TimestampsBlock
     *
     * @apiError (Exceptions) {Exception} UserNotFound  User with given ID not found
     */
    public function show(Request $request)
    {
        $user = User::find($request->id);
        if (!$user) {
            throw new APIException('OBJECT_NOT_FOUND', 404);
        }
        return ['user' => $user];
    }


    /**
     * @api {post} /auth/register Register a new user
     * @apiVersion 0.1.0
     * @apiName RegisterUser
     * @apiGroup User
     * @apiPermission user
     *
     * @apiDescription Register a new User -
     * Use this endpoint to register a new user
     *
     * @apiParam {String}    [name]                Name of User
     * @apiParam {String}    email               Email address of user
     * @apiParam {String}    password            Hashed password of user
     * @apiParam {String}    frontend_url        Frontend URL
     *
     * @apiSuccess {Object[]}  user               user
     * @apiUse UserReturnSuccess
     *
     * @apiUse TimestampsBlock
     *
     * @apiError (Exceptions) {Exception} EmailAlreadyExists  Email address already exists
     * @apiError (Exceptions) {Exception} FrontendURLMissing  Frontend URL not given
     * @apiError (Exceptions) {Exception} PasswordRules       Given password does not match the rules
     * @apiError (Exceptions) {Exception} CustomerCreateError Validation of Model failed. See status.returnValues
     * @apiError (Exceptions) {Exception} SendMailError       Not possible to send Validationcode to user
     */
    public function register(Request $request)
    {
        $user = User::where('email', '=', $request->email)->first();
        /**if(!$request->frontend_url){
         * throw new APIException('PARAM_FRONTEND_URL_MISSING',500);
         * }*/
        if (!$user) {
            if (passwordValidate($request->password)) {
                $user = new User;
                $user->setSafeAttributes(Input::all());

                do {
                    $contractId = mt_rand(1000000000, 9999999999);
                    $contract = Customer::where('contract', $contractId)->first();
                } while ($contract);

                $customer = new Customer;
                $customer->contract = $contractId;
                $customer->setSafeAttributes(Input::all());
                $customer->abonnement_id = config('app.start_abo_id');
                if ($customer->save()) {
                    $objectPermission = $customer->abonnement->objectPermission;

                    if (!$objectPermission) {
                        // toDO: throw exception: EXCEPTION_ERROR -> CODE => ...
                        throw new APIException('EXCEPTION_ERROR', 500, null, ['CODE' => 'Object permissions for abo not found.']);
                    }

                    if (!$customer->abonnement->objectPermission->cloneFor($customer)) {
                        throw new APIException('OBJECT_CREATE_ERROR', 500, $objectPermission->getValidationErrorMessages());
                    }

                    $user->customer_id = $customer->id;
                    $user->validationcode = md5(getRandomString(32, 'string') . $user->customer_id);
                    if ($user->save()) {

                        //set permissions
                        $op = ObjectPermission::where("object_type", "user")->where("object_id", '1')->first();
                        $newRights = $user->setUserPermissions();
                        $newop = $op->replicate();
                        $newop->object_type = $op->object_type;
                        $newop->object_id = $user->id;
                        $newop->permissions = json_encode($newRights);
                        $newop->properties = $op->properties;
                        $newop->save();

                        /*$user->frontend_url = $request->frontend_url;*/
                        Event::fire(new UserRegisteredEvent($user));

                        // Create Addressbooks -
                        /*unset($user->frontend_url);*/
                        $userAddressbook = new Addressbook;
                        $userAddressbook->customer_id = $user->customer_id;
                        $userAddressbook->user_id = $user->id;
                        $userAddressbook->object_type = 'user';
                        $userAddressbook->object_id = $user->id;
                        $userAddressbook->type = 'user';
                        $userAddressbookCompany = $userAddressbook->replicate();
                        $customerAddressbook = $userAddressbook->replicate();
                        $userAddressbookCompany->type = 'company';
                        $customerAddressbook->object_type = 'customer';
                        $customerAddressbook->object_id = $user->customer_id;
                        unset($customerAddressbook->type);
                        $userAddressbookCompany->save();
                        $userAddressbook->save();
                        $customerAddressbook->save();

                        // Create productprofiles for User
                        $schemas = config('default_import.productprofiles');

                        foreach ($schemas as $schema) {
                            $productprofile = new Productprofile;
                            $productprofile->name = $schema['name'];
                            $productprofile->user_id = $user->id;
                            $productprofile->customer_id = $user->customer_id;
                            $productprofile->folder = $schema['folder'];
                            $productprofile->save();
                        }

                        // create productschemas for user
                        $schemas = config('default_import.productschemas');

                        foreach ($schemas as $schema) {
                            $productschema = new Productschema;
                            $schema = (object)$schema;
                            $schema->id = null;
                            $schema->is_default = null;
                            User::setCurrent($user);
                            $productschema->seprateAndSave($schema);
                        }

                        return ['user' => $user];
                    }
                    throw new APIException('OBJECT_CREATE_ERROR', 500, $user->getValidationErrorMessages());
                }
                throw new APIException('OBJECT_CREATE_ERROR', 404, $customer->getValidationErrorMessages());
            }
            throw new APIException('USER_PASSWORD_RULES', 500);
        }
        throw new APIException('USER_EMAIL_ALREADY_EXISTS', 500);
    }

    /**
     * @api {get} /auth/confirm/:validationcode Validate User
     * @apiVersion 0.1.0
     * @apiName ValidateUser
     * @apiGroup User
     * @apiPermission user
     *
     * @apiDescription Validate an user
     *
     * @apiSuccess {Object[]}  users User
     * @apiUse UserReturnSuccess
     *
     * @apiError (Exceptions) {Exception} UserAlreadyValidated   The user is already validated
     * @apiError (Exceptions) {Exception} UserNotFound           User does not exist or wrong validationcode
     * @apiError (Exceptions) {Exception} UserUpdateError        Update user failed
     */
    //must be called validation instead of validate because an other function with the same name exists
    public function validation(Request $request)
    {
        #dd($request->validationcode);

        $user = User::where('validationcode', '=', $request->validationcode)->first();
        if ($user) {
            if ($user->status != 1) {
                $user->status = 1;
                $user->validated_at = date("Y-m-d H:i:s");
                if ($user->save()) {

                    //create schema folders
                    Folder::cloneFromFolderschemaFor($user, Folderschema::ROOT_IDENT);
                    // setup folder images
                    $user->insertFolderImages($user->id, $user->customer_id);

                    Event::fire(new UserValidatedEvent($user));
                    if(config('app.elastic_search')){
                        (new SeedToElastic($user))->seed();
                    }
                    return ['user' => $user];
                } else {
                    throw new APIException('OBJECT_UPDATE_ERROR', 500, $user->getValidationErrorMessages());
                }
            } else {
                throw new APIException('USER_ALREADY_VALIDATED', 404);
            }
        } else {
            throw new APIException('OBJECT_NOT_FOUND', 404);
        }
    }

    /**
     * @api {get} /auth/forgot/password Send a password reset token to user
     * @apiVersion 0.1.0
     * @apiName UserForgotPassword
     * @apiGroup User
     * @apiPermission user
     *
     * @apiDescription Send a password reset token to user
     *
     * @apiParam {String}    email      Email adress of the user
     *
     * @apiError (Exceptions) {Exception} EmailDoesNotExist user with given email not found
     * @apiError (Exceptions) {Exception} ObjectUpdateFailed Validation of Model failed. See status.returnValues
     */
    public function forgotPasswort(Request $request)
    {

        $user = User::where('email', '=', $request->email)->first();

        if ($user) {
            $user->resettoken = md5(getRandomString(32, 'string') . $user->id . date("Y-m-d H:i:s"));
            if ($user->save()) {
                Event::fire(new UserForgotPasswordEvent($user));
            } else {
                throw new APIException('OBJECT_UPDATE_ERROR', 500, $user->getValidationErrorMessages());
            }
        } else {
            throw new APIException('USER_EMAIL_DOES_NOT_EXIST', 404);
        }
    }

    /**
     * @api {put} /users/password Change user password
     * @apiVersion 0.1.0
     * @apiName ChangeUserPassword
     * @apiGroup User
     * @apiPermission user
     *
     * @apiDescription Send a validationcode for changing password to user
     *
     * @apiParam {String}    oldpassword         Old password of user
     * @apiParam {String}    newpassword         New Password of user
     * @apiParam {String}    frontend_url        Frontend URL
     *
     * @apiError (Exceptions) {Exception} FrontendURLMissing  Frontend URL not given
     * @apiError (Exceptions) {Exception} PasswordRules      Given password does not match the rules
     * @apiError (Exceptions) {Exception} WrongPassword      Wrong password given
     * @apiError (Exceptions) {Exception} ObjectUpdateFailed Validation of Model failed. See status.returnValues
     */

    public function changePassword(Request $request)
    {
        # test123 = $2y$10$stZ9N4YpTCa414V1/WZ/A.2preKvP4Tu2UuhoyJRikLmDePmnB3La
        if (!$request->frontend_url) {
            throw new APIException('PARAM_FRONTEND_URL_MISSING', 500);
        }
        if (passwordValidate($request->newpassword)) {
            if (Hash::check($request->oldpassword, $this->currentUser->password)) {
                #$this->currentUser->password_cache = Hash::make($request->newpassword);
                $this->currentUser->password_cache = Crypt::encrypt($request->newpassword);

                $this->currentUser->pw_change_validationcode = md5(getRandomString(32, 'string') . $this->currentUser->id . date("Y-m-d H:i:s"));
                if ($this->currentUser->save()) {
                    $this->currentUser->frontend_url = $request->frontend_url;
                    Event::fire(new UserChangePasswordEvent($this->currentUser));
                } else {
                    throw new APIException('OBJECT_UPDATE_ERROR', 500, $this->currentUser->getValidationErrorMessages());
                }
            } else {
                throw new APIException('USER_WRONG_PASSWORD', 500);
            }
        } else {
            throw new APIException('USER_PASSWORD_RULES', 500);
        }
    }

    /**
     * @api {put} /users/password/:pw_change_validationcode Confirm change user password
     * @apiVersion 0.1.0
     * @apiName ChangeUserPasswordValidation
     * @apiGroup User
     * @apiPermission user
     *
     * @apiDescription Change the user password where given settoken is set
     *
     * @apiError (Exceptions) {Exception} UserPwChangeValidationcodeInvalid  The given validationcode is invalid
     * @apiError (Exceptions) {Exception} ObjectUpdateFailed         Validation of Model failed. See status.returnValues
     */
    public function updatePassword(Request $request)
    {

        $user = User::where('pw_change_validationcode', '=', $request->pw_change_validationcode)->first();
        if ($user) {
            $user->password = Crypt::decrypt($user->password_cache);
            $user->password_cache = "";
            $user->pw_change_validationcode = "";
            if ($user->save()) {
                Event::fire(new UserChangePasswordValidatedEvent($user));
            } else {
                throw new APIException('OBJECT_UPDATE_ERROR', 500, $user->getValidationErrorMessages());
            }
        } else {
            throw new APIException('USER_PW_CHANGE_VALIDATIONCODE_INVALID', 500);
        }

    }

    /**
     * @api {get} /auth/password/reset Validate Resettoken
     * @apiVersion 0.1.0
     * @apiName ValidateUserToken
     * @apiGroup User
     * @apiPermission user
     *
     * @apiDescription Check if the given token is valid
     *
     * @apiParam {String}    resettoken      Reset token of user
     *
     * @apiError (Exceptions) {Exception} ResettokenInvalid the given token is invalid
     */
    public function validateToken(Request $request)
    {

        $user = User::where('resettoken', '=', $request->resettoken)->first();

        if ($user) {

        } else {
            throw new APIException('USER_RESETTOKEN_INVALID', 500);
        }
    }

    /**
     * @api {put} /auth/password/reset Set new password to user
     * @apiVersion 0.1.0
     * @apiName UserResetPassword
     * @apiGroup User
     * @apiPermission user
     *
     * @apiDescription Set a new password for the user
     *
     * @apiParam {String}    resettoken      Reset token of the user
     * @apiParam {String}    password        Password of the user
     *
     * @apiSuccess {Object[]}  users User
     * @apiUse UserReturnSuccess
     *
     * @apiError (Exceptions) {Exception} PasswordNotGiven   New password not given
     * @apiError (Exceptions) {Exception} PasswordRules      Given password does not match the rules
     * @apiError (Exceptions) {Exception} ObjectNotFound     User with given reset token not found
     * @apiError (Exceptions) {Exception} ObjectUpdateFailed Validation of Model failed. See status.returnValues
     * @apiError (Exceptions) {Exception} ResettokenNotGiven   Resettoken not given
     */
    public function passwordReset(Request $request)
    {
        if (empty($request->resettoken)) {
            throw new APIException('USER_RESETTOKEN_NOT_GIVEN', 500);
        }
        if (!empty($request->password)) {
            // Cannot set password min lenght as validation rule, because the auth system encrypts earlier
            if (passwordValidate($request->password)) {
                $user = User::where('resettoken', '=', $request->resettoken)->first();
                if ($user) {
                    $user->password = $request->password;
                    $user->resettoken = "";
                    if ($user->save()) {
                        return ['user' => $user];
                    } else {
                        throw new APIException('OBJECT_UPDATE_ERROR', 500, $user->getValidationErrorMessages());
                    }
                } else {
                    throw new APIException('OBJECT_NOT_FOUND', 404);
                }
            } else {
                throw new APIException('USER_PASSWORD_RULES', 500);
            }
        } else {
            throw new APIException('USER_PASSWORD_NOT_GIVEN', 500);
        }
    }

    /**
     * @api {post} /users/:id Update User
     * @apiVersion 0.1.0
     * @apiName UpdateUser
     * @apiGroup User
     * @apiPermission user
     *
     * @apiDescription Update a User -
     * Use this endpoint to update a user
     * Uses POST as Fileuploads do not work with PUT in some circumstances
     *
     * @apiParam {File}      [avatarfile]          INPUT-FILE: The users avatar file
     * @apiParam {String}    [name]                Name of User
     * @apiParam {String}    [email]               Email address of user
     * @apiParam {String}    [password]            Hashed password of user
     * @apiParam {Integer}   [online_visibility]   Enum values: 0 = Unknown/not given, 1 = Online, 2 = Away, 3 = Do not disturb, 4 = Invisible, 5 = Offline
     *
     * @apiDescription Updates a user
     *
     * @apiSuccess {Object[]}  users User
     * @apiUse UserReturnSuccess
     *
     * @apiError (Exceptions) {Exception} ObjectUpdateFailed Validation of Model failed. See status.returnValues
     */
    public function update(Request $request)
    {
        $userObject = $this->findObject($request);

        $userObject->setSafeAttributes(Input::all());
        if ($userObject->saveWithAttachment($request->file('avatarfile'))) {
            return ['user' => $userObject];
        }

        throw new APIException('OBJECT_UPDATE_ERROR', 500, $userObject->getValidationErrorMessages());
    }

    /**
     * @api {get} /users/:id/status Get the online visibility of an user
     * @apiVersion 0.1.0
     * @apiName UserOnlineVisibilty
     * @apiGroup User
     * @apiPermission user
     *
     * @apiDescription Retrieve the online visibility of an user
     *
     *
     * @apiSuccess {string}  online_visibility   Enum values: 0 = Unknown/not given, 1 = Online, 2 = Away, 3 = Do not disturb, 4 = Invisible, 5 = Offline
     *
     * @apiError (Exceptions) {Exception} ObjectNotFound User with given id not found
     */
    public function onlineVisibility(Request $request)
    {
        $user = User::find($request->id);
        if ($user) {
            return ['online_visibility' => $user->online_visibility];
        } else {
            throw new APIException('OBJECT_NOT_FOUND', 404);
        }
    }

    /**
     * @api {put} /users/:id/status Set the online visibility for the current user
     * @apiVersion 0.1.0
     * @apiName UserOnlineVisibiltyUpdate
     * @apiGroup User
     * @apiPermission user
     *
     * @apiDescription Update the online visibility for current user
     *
     * @apiParam {Integer}   [online_visibility]   Enum values: 0 = Unknown/not given, 1 = Online, 2 = Away, 3 = Do not disturb, 4 = Invisible, 5 = Offline
     *
     * @apiSuccess {string}  online_visibility   Enum values: 0 = Unknown/not given, 1 = Online, 2 = Away, 3 = Do not disturb, 4 = Invisible, 5 = Offline
     *
     * @apiError (Exceptions) {Exception} UserNoPermission User with given id has no permission to change the online visibility of other users
     * @apiError (Exceptions) {Exception} ObjectNotFound User with given id not found
     * @apiError (Exceptions) {Exception} ObjectUpdateFailed Validation of Model failed. See status.returnValues
     */
    public function updateOnlineVisibility(Request $request)
    {
        parent::checkUserRights($request, "mydesk.contacts.canChangeContactStatus");
        $user = User::find($request->id);
        if ($user) {
            $visibilities = $user->getEnumValuesFor(strtoupper('ONLINE_VISIBILITY'));
            $user->online_visibility = $request->online_visibility;
            if (in_array($request->online_visibility, $visibilities) && $user->save()) {
                return ['online_visibility' => $user->online_visibility];
            } else {
                throw new APIException('OBJECT_UPDATE_ERROR', 500, $user->getValidationErrorMessages());
            }
        } else {
            throw new APIException('OBJECT_NOT_FOUND', 404);
        }
    }

    /**
     * @api {delete} /users/:id Delete user
     * @apiVersion 0.1.0
     * @apiName DeleteUser
     * @apiGroup User
     * @apiPermission user
     *
     * @apiDescription Delete user -
     * Use this endpoint to delete a user
     *
     * @apiError (Exceptions) {Exception} ObjectUpdateError    user with given ID could not be updated
     * @apiError (Exceptions) {Exception} ObjectNotFound       user with given ID not found
     */

    public function delete(Request $request)
    {
        if ($request->id == $this->currentUser->id) {
            $user = User::find($request->id);

            if ($user) {
                if ($user->delete()) {
                    JWTAuth::setToken($request->token)->invalidate();
                } else {
                    throw new APIException('OBJECT_UPDATE_ERROR', 500, $user->getValidationErrorMessages());
                }
                // }  // throw new APIException('OBJECT_NOT_FOUND',404);
                // else{
            }
        } else {
            throw new APIException('USER_NO_PERMISSION', 500);
        }

    }


    /**
     * @api {get} /me Get Userinformations
     * @apiVersion 0.1.0
     * @apiName UserMe
     * @apiGroup User
     * @apiPermission user
     *
     * @apiDescription Get Informations about the currentUser
     *
     *
     * @apiSuccess {Object[]}  User               User
     * @apiUse UserReturnSuccess
     * @apiSuccess {Object[]}  permissions Permissions
     * @apiSuccess {Object[]}  accountProperties Properties
     *
     */
    public function me(Request $request)
    {
        $user = $this->currentUser;
        $data = $user->permissionList();
        $returnarray = [
            "user" => $user->jsonSerialize(['attachment_token']),
            "permissions" => $data['permissions'],
            "accountProperties" => []
        ];

        return $returnarray;
    }

    /**
     * @api {get} /me/permissions Get Userpermissions
     * @apiVersion 0.1.0
     * @apiName UserMePermissions
     * @apiGroup User
     * @apiPermission user
     *
     * @apiDescription Get Permissions of the currentUser
     *
     *
     * @apiSuccess {Object[]}  permissions Permissions
     *
     */
    public function me_permissions(Request $request)
    {
        $user = $this->currentUser;
        $data = $user->permissionList();
        $returnarray = [
            "permissions" => $data['permissions']
        ];

        return $returnarray;
    }

    /**
     * @api {get} /me/properties Get UserProperties
     * @apiVersion 0.1.0
     * @apiName UserMeProperties
     * @apiGroup User
     * @apiPermission user
     *
     * @apiDescription Get Properties of the currentUser
     *
     *
     * @apiSuccess {Object[]}  accountProperties Properties
     *
     */
    public function me_properties(Request $request)
    {
        $user = $this->currentUser;

        $returnarray = [
            "accountProperties" => []
        ];

        return $returnarray;
    }


    ################
    # PRIVATE AREA #
    ################

    private function findObject($request)
    {
        $userObject = User::find($request->id);

        if (!$userObject) {
            throw new APIException('OBJECT_NOT_FOUND', 404);
        }

        // toDo: check rights for user <-> customer
        if (false) {
            throw new APIException('AUTH_MISSING_RIGHTS', 403);
        }

        return $userObject;
    }


    /**
     * @api {get} /all/users Retrieve userlist which are not in current user company account
     * @apiVersion 0.1.0
     * @apiName GetUsers
     * @apiGroup User
     * @apiPermission user
     *
     * @apiDescription Retrieve a List of users which are not in current user company account
     *
     * @apiSuccess {Object[]}  users User
     * @apiUse UserReturnSuccess
     */
    public function getUsers(Request $request)
    {
        return PaginationHelper::execPagination(
            'users',
            User::where('customer_id', '!=', User::current()->customer_id),
            $request
        );
    }


    public function getNotifications(Request $request)
    {
        return PaginationHelper::execPagination(
            'notifications',
            Notification::with('to', 'from')->where('to_user_id', User::current()->id)->latest()->take(5)->get()->all(),
            $request
        );
    }

    public function saveLanguage(Request $request)
    {
        $user = User::find($this->currentUser->id);
        if ($user) {
            $user->customer_language_code = $request->code;
            $user->customer_language_flag = $request->flag;
            if ($user->save()) {
                return ['user' => $user];
            } else {
                throw new APIException('OBJECT_UPDATE_ERROR', 500, $user->getValidationErrorMessages());
            }
        }
    }

}

