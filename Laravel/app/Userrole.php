<?php

namespace App;

use App\AppModel;

class Userrole extends AppModel
{
    protected $guarded = ['created_at', 'updated_at', 'deleted_at'];

    protected $hidden = [
        'deleted_at'
    ];


    /*
     * ------------------------ 
     * RELATIONS BLOCK
     * ------------------------
     */

    /* BELONGS TO */

    public function customer()
    {
        return $this->belongsTo('App\Customer');
    }

    /* HABTM */

    public function users()
    {
        return $this->belongsToMany('App\User', 'user_usergroups');
    }

    public function usergroups()
    {
        return $this->belongsToMany('App\Usergroup', 'user_usergroups');
    }
}
