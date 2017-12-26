<?php

use Illuminate\Foundation\Testing\WithoutMiddleware;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use App\User;
use App\Exceptions\APIException;

class UserTest extends TestCase
{
    /**
     * A basic test example.
     *
     * @return void
     */
    
	/** @test */
    public function registerPasswordToShort(){
    	$this->json('POST', '/api/auth/register', ['email' => 'emailthatdoesntexist@our.host', 'password' => 'Pp!12', 'frontend_url' => 'http://testurl.de/'])
            ->seeJson($this->seeErrorException('USER_PASSWORD_RULES'));
    
    }

    /** @test */
    public function registerPasswordNonNum(){
    	$this->json('POST', '/api/auth/register', ['email' => 'emailthatdoesntexist@our.host', 'password' => 'Pp!DDs', 'frontend_url' => 'http://testurl.de/'])
            ->seeJson($this->seeErrorException('USER_PASSWORD_RULES'));
    
    }

    /** @test */
    public function registerPasswordNonUc(){
    	$this->json('POST', '/api/auth/register', ['email' => 'emailthatdoesntexist@our.host', 'password' => 'pp!123f', 'frontend_url' => 'http://testurl.de/'])
            ->seeJson($this->seeErrorException('USER_PASSWORD_RULES'));
    
    }

    /** @test */
    public function registerPasswordNonLc(){
    	$this->json('POST', '/api/auth/register', ['email' => 'emailthatdoesntexist@our.host', 'password' => 'PP!123F', 'frontend_url' => 'http://testurl.de/'])
            ->seeJson($this->seeErrorException('USER_PASSWORD_RULES'));
    
    }

    /** @test */
    public function registerPasswordTooLong(){


	    $this->json('POST', '/api/auth/register', ['email' => 'emailthatdoesntexist@our.host', 'password' => 'PP!123rsFPP!123rsFPP!123rsFPP!123rsFPP!123rsFPP!123srgqwe54ge5ggg', 'frontend_url' => 'http://testurl.de/'])
            ->seeJson($this->seeErrorException('USER_PASSWORD_RULES'));
    }

    /** @test */
    public function registerEmailAlreadyExist(){
    	$this->json('POST', '/api/auth/register', ['email' => $this->email, 'DKQFGWZ3dj!"', 'frontend_url' => 'http://testurl.de/'])
            ->seeJson($this->seeErrorException('USER_EMAIL_ALREADY_EXISTS'));
    }

    /** @test */
    public function registerSaveValidationError(){
    	$this->json('POST', '/api/auth/register', ['email' => '', 'DKQFGWZ3dj!"', 'frontend_url' => 'http://testurl.de/'])
            ->seeJson($this->seeErrorException('USER_PASSWORD_RULES'));
    }

    /** @test */
    public function registerNoFrontendURL(){
        $mail['email'] = 'valid-email'.mt_rand(1000,1000000).'@millepondo.de';
        $this->json('POST', '/api/auth/register', ['email' => $mail['email'], 'password' => env('TESTUSER_PASSWORD')])
            ->seeJson($this->seeErrorException('PARAM_FRONTEND_URL_MISSING'));          
    }
    
    /** @test */
    public function registerSuccess(){
        $mail['email'] = 'valid-email'.mt_rand(1000,1000000).'@millepondo.de';
        $this->json('POST', '/api/auth/register', ['email' => $mail['email'], 'password' => env('TESTUSER_PASSWORD'), 'frontend_url' => 'http://testurl.de/'])
            ->seeJsonStructure(['user' => ['email', 'customer_id', 'updated_at', 'created_at', 'id', 'avatarURL'], 'status' => ['msg']]);     
        return $mail;     
    }

    /**
    * @test 
    * @depends registerSuccess
    */
    public function loginWithoutValidation($mail){
        $this->json('POST', '/api/authenticate', ['email' => $mail['email'], 'password' => env('TESTUSER_PASSWORD')])
            ->seeJson($this->seeErrorException('AUTH_USER_NOT_VALIDATED'));
    }

    /**
    * @test 
    * @depends registerSuccess
    */
    public function invalidUserValidationcode(){
        $this->json('get', '/api/auth/confirm/InvalidValidationCode')
            ->seeJson($this->seeErrorException('OBJECT_NOT_FOUND'));
    }

    /**
    * @test 
    * @depends registerSuccess
    */
    public function validUserValidationcode($mail){
        $user = User::where('email', $mail['email'])->first();
        $code['validationcode'] = $user->validationcode;
        $this->json('get', "/api/auth/confirm/".$code['validationcode'])
            ->seeJsonStructure($this->getUserStructure());
            return $code;
    }

    /**
    * @test 
    * @depends validUserValidationcode
    */
    public function alreadyValidaetUserValidationcode($code){
        $this->json('get', "/api/auth/confirm/".$code['validationcode'])
            ->seeJson($this->seeErrorException('USER_ALREADY_VALIDATED'));
    }

    /**
    * @test 
    * @depends validUserValidationcode
    */
    public function forgotPasswordInvalidEmail(){
        $this->json('get', '/api/auth/forgot/password', ['email' => '1.de'])
            ->seeJson($this->seeErrorException('USER_EMAIL_DOES_NOT_EXIST'));
    }

    /**
    * @test 
    * @depends registerSuccess
    */
    public function forgotPasswordValidEmail($mail){
        $this->json('get', '/api/auth/forgot/password', ['email' => $mail['email']])
            ->seeJson(['status' => ['msg' => 'OK']]);
    }

    /**
    * @test 
    * @depends validUserValidationcode
    */
    public function forgotPasswordResettokenEmpty(){
        $this->json('put', '/api/auth/password/reset', ['password' => env('TESTUSER_PASSWORD'), 'resettoken' => ''])
            ->seeJson($this->seeErrorException('USER_RESETTOKEN_NOT_GIVEN'));
    }

    /**
    * @test 
    * @depends validUserValidationcode
    */
    public function forgotPasswordResettokenInvalid(){
        $this->json('put', '/api/auth/password/reset', ['password' => env('TESTUSER_PASSWORD'), 'resettoken' => 'invalid'])
            ->seeJson($this->seeErrorException('OBJECT_NOT_FOUND'));   
    }

    /**
    * @test 
    * @depends registerSuccess
    */
    public function forgotPasswordInvalidNewPassword($mail){
        $user = User::where('email', $mail['email'])->first();
        $this->json('put', '/api/auth/password/reset', ['password' => 'Va!idPass', 'resettoken' => $user->resettoken])
            ->seeJson($this->seeErrorException('USER_PASSWORD_RULES'));
            return $user;
    }

    /** @test */
    public function validateResettokenInvalid(){
        $this->json('get', '/api/auth/password/reset', ['resettoken' => 'invalid'])
            ->seeJson($this->seeErrorException('USER_RESETTOKEN_INVALID'));
    }

    /**
    * @test 
    * @depends forgotPasswordInvalidNewPassword
    */
    public function validateResettokenValid($user){
        $this->json('get', '/api/auth/password/reset', ['resettoken' => $user->resettoken])
            ->seeJson(['status' => ['msg' => 'OK']]);
    }

    /**
    * @test 
    * @depends forgotPasswordInvalidNewPassword
    */
    public function forgotPasswordSuccess($user){
        $this->json('put', '/api/auth/password/reset', ['password' => env('TESTUSER_PASSWORD'), 'resettoken' => $user->resettoken])
            ->seeJsonStructure($this->getUserStructure());
    }

    /**
    * @test 
    * @depends registerSuccess
    */
    public function changePasswordInvalidPassword(){
        $this->checkLoginRequired("PUT", "/api/users/password");
        $this->jsonWithHeader('PUT', "/api/users/password", ['oldpassword' => env('TESTUSER_PASSWORD'), 'newpassword' => 'invalid', 'frontend_url' => 'http://testurl.de/'])
            ->seeJson($this->seeErrorException('USER_PASSWORD_RULES'));
    }

    /**
    * @test 
    * @depends registerSuccess
    */
    public function changePasswordWrongPassword(){
        $this->checkLoginRequired("PUT", "/api/users/password");
        $this->jsonWithHeader('PUT', "/api/users/password", ['oldpassword' => env('TESTUSER_PASSWORD').'2', 'newpassword' => env('TESTUSER_PASSWORD'), 'frontend_url' => 'http://testurl.de/'])
            ->seeJson($this->seeErrorException('USER_WRONG_PASSWORD'));
    }

    /**
    * @test 
    * @depends registerSuccess
    */
    public function changePasswordNoFrontendURL(){
        $this->checkLoginRequired("PUT", "/api/users/password");
        $this->jsonWithHeader('PUT', "/api/users/password", ['oldpassword' => env('TESTUSER_PASSWORD'), 'newpassword' => env('TESTUSER_PASSWORD')])
            ->seeJson($this->seeErrorException('PARAM_FRONTEND_URL_MISSING'));  
    }

    /**
    * @test 
    * @depends registerSuccess
    */
    public function changePasswordCorrectPassword(){
        $this->checkLoginRequired("PUT", "/api/users/password");
        $this->jsonWithHeader('PUT', "/api/users/password", ['oldpassword' => env('TESTUSER_PASSWORD'), 'newpassword' => env('TESTUSER_PASSWORD'), 'frontend_url' => 'http://testurl.de/'])
            ->seeJson(['status' => ['msg' => 'OK']]);
        $user = $this->getCurrentUserMe();
        $return['currentUser_id'] = $user['user']['id'];
        $user = User::where('email', env('TESTUSER_USERNAME'))->first();
        $return['pw_change_validationcode'] = $user->pw_change_validationcode;
        return $return;
    }

    /**
    * @test 
    * @depends changePasswordCorrectPassword
    */
    public function updatePasswordInvalidCode(){
        $this->checkLoginRequired("PUT", "/api/users/password/");
        $this->jsonWithHeader('PUT', "/api/users/password/invalidcode")
            ->seeJson($this->seeErrorException('USER_PW_CHANGE_VALIDATIONCODE_INVALID'));
    }

    /**
    * @test 
    * @depends changePasswordCorrectPassword
    */
    public function updatePasswordValidCode($user){
        $this->checkLoginRequired("PUT", "/api/users/password/");
        $this->jsonWithHeader('PUT', "/api/users/password/".$user['pw_change_validationcode'])
            ->seeJson(['status' => ['msg' => 'OK']]);
    }

    /** @test */
    public function getUserStatusInvalidID(){
        $this->jsonWithHeader('GET', "/api/users/1/status")
            ->seeJson($this->seeErrorException('OBJECT_NOT_FOUND'));
    }

    /**
    * @test 
    * @depends changePasswordCorrectPassword
    */
    public function getUserStatusValidID($user){
        $this->jsonWithHeader('GET', "/api/users/{$user['currentUser_id']}/status")
            ->seeJsonStructure(['online_visibility', 'status' => ['msg']]);
    }

    /** @test */
    public function updateUserStatusInvalidID(){
        $this->jsonWithHeader('PUT', "/api/users/1/status", ['online_visibility' => 1])
            ->seeJson($this->seeErrorException('OBJECT_NOT_FOUND'));
    }

    /**
    * @test 
    * @depends changePasswordCorrectPassword
    */
    public function updateUserStatusInvalidStatus($user){
        $this->jsonWithHeader('PUT', "/api/users/{$user['currentUser_id']}/status", ['online_visibility' => 'invalid'])
            ->seeJson(['error' => ['code' => 'OBJECT_UPDATE_ERROR', 'msg' => 'Unable to update the object - see validation errors', 'returnValues' => []]]);
    }

    /**
    * @test 
    * @depends changePasswordCorrectPassword
    */
    public function updateUserStatusValidStatus($user){
        $this->jsonWithHeader('PUT', "/api/users/{$user['currentUser_id']}/status", ['online_visibility' => '2'])
            ->seeJson(['online_visibility' => '2', 'status' => ['msg' => 'OK']]);
    }

    /** @test */
    public function meProperties(){
        $this->jsonWithHeader('GET', "/api/me/properties")
            ->seeJsonStructure(['accountProperties' => []]);
        #dd(json_decode($this->response->getContent(), true));
    }

    /**
    * @test 
    * @depends changePasswordCorrectPassword
    */
    public function updateUser($user){
        $this->jsonWithHeader('POST', "/api/users/".$user['currentUser_id'], ['name' => 'changedName'])
            ->seeJsonStructure($this->getUserStructure());
    }

    /** @test */
    public function getUserList(){
        $this->checkLoginRequired("get", "/api/users/");
        $this->jsonWithHeader("get", "/api/users/")
            ->seeJsonStructure($this->getUsersStructure());
        $tempUserList = json_decode($this->response->getContent(), true);
        $user = $tempUserList['users']['0']['id'];
        return $user;
    }

    /** @test */
    public function getSingleUserInvalidId(){
        $this->checkLoginRequired("GET", "/api/users/1");
        $this->jsonWithHeader('GET', "/api/users/1")
            ->seeJson($this->seeErrorException('OBJECT_NOT_FOUND'));
    }

    /**
    * @test 
    * @depends getUserList
    */
    public function getSingleUser($user){
        $this->checkLoginRequired("GET", "/api/users/".$user);
        $this->jsonWithHeader('GET', "/api/users/".$user)
            ->seeJsonStructure($this->getUserStructure());
    }

    /** @test */
    public function testRefreshToken(){
        $this->checkLoginRequired("put", "/api/authenticate/");
        $this->jsonWithHeader("put", "/api/authenticate/")
            ->seeJsonStructure(['token']);

        $token = json_decode($this->response->getContent(), true);
        $token = $token['token'];

        $this->jsonWithHeader("get", "/api/me/")
            ->seeJson($this->seeErrorException('AUTH_TOKEN_INVALID'));

        return $token;
    }

    /**
    * @test 
    * @depends registerSuccess
    */
    public function deleteRegisteredUser($mail){
        $this->json('POST', '/api/authenticate', ['email' => $mail['email'], 'password' => env('TESTUSER_PASSWORD')]);
        $token = json_decode($this->response->getContent(), true);

        $this->json('GET','/api/me/',[],['Authorization' => 'Bearer '.$token['token']]);
        $user = json_decode($this->response->getContent(), true);

        $this->json('DELETE','/api/users/1',[],['Authorization' => 'Bearer '.$token['token']])
        ->seeJson($this->seeErrorException('USER_NO_PERMISSION'));

        $this->json('DELETE','/api/users/'.$user['user']['id'],[],['Authorization' => 'Bearer '.$token['token']])
        ->seeJson($this->seeErrorException('AUTH_TOKEN_INVALID'));
        #dd($this->response->getContent());
    }

    /**
    * @test 
    */
    public function testNewToken(){
        $this->json('POST', '/api/authenticate', ['email' => env('TESTUSER_USERNAME'), 'password' => env('TESTUSER_PASSWORD')]);
        $token = json_decode($this->response->getContent(), true);

        sleep(2);

        $this->json('PUT','/api/authenticate',[],['Authorization' => 'Bearer '.$token['token']]);
        $fresh_token = json_decode($this->response->getContent(), true);

        sleep(2);

        $this->json('GET','/api/me',[],['Authorization' => 'Bearer '.$fresh_token['token']])
        #dd(json_decode($this->response->getContent(), true));
            ->seeJsonStructure($this->getUserStructure());
    }
}
