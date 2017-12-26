<?php

use App\Exceptions\APIException;

/**
 * GENERAL BRIEFING:
 *
 * GET        -> index, details
 * POST    -> create
 * PUT        -> update
 * DELETE    -> delete
 */

Route::group(['prefix' => 'elastic', 'middleware' => ['web']], function(){
    Route::get('demo','ElasticDemoController@index');
});

Route::group(['prefix' => 'api', 'middleware' => 'api.responseFormater'], function () {
    // AUTHENTICATE
    Route::post('/authenticate', 'AuthenticateController@authenticate');
    Route::put('/authenticate', 'AuthenticateController@refreshToken');
    Route::post('auth/facebook', 'AuthenticateController@facebook');
    Route::post('auth/google', 'AuthenticateController@google');
    Route::post('auth/twitter', 'AuthenticateController@twitter');
    Route::post('auth/linkedin', 'AuthenticateController@linkedin');
    Route::post('auth/instagram', 'AuthenticateController@instagram');
    Route::post('auth/pinterest', 'AuthenticateController@pinterest');
    Route::get('/decodetoken/', 'AuthenticateController@decodetoken');
// /* fallback
Route::any('{all}', function () {
    throw new APIException('REQUEST_INVALID', 404);
})->where('all', '.*');
