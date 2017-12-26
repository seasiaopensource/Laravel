<?php

namespace App;

use Hash;
use Illuminate\Support\Facades\Input;
use Illuminate\Support\Facades\Validator;

use Illuminate\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Foundation\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Contracts\Auth\Access\Authorizable as AuthorizableContract;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;

use JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;

use App\Providers\RequestStorageProvider;

use App\Folder;
use App\Folderschema;
use App\Object2Object;
use App\Project;
use App\Permission;

class User extends AppModel implements AuthenticatableContract, AuthorizableContract, CanResetPasswordContract
{

    const ONLINE_VISIBILITY_UNKNOWN = "0";
    const ONLINE_VISIBILITY_ONLINE = "1";
    const ONLINE_VISIBILITY_AWAY = "2";
    const ONLINE_VISIBILITY_DO_NOT_DISTURB = "3";
    const ONLINE_VISIBILITY_INVISIBLE = "4";
    const ONLINE_VISIBILITY_OFFLINE = "5";

    use Authenticatable, Authorizable, CanResetPassword;

    use Traits\ActsAsAttachmentTrait;
    use Traits\EnumConstSelectionTrait;
    use Traits\ActsAsAccessableTrait;

    public static $passwordMinLength = 6;

    protected $casts = [
        'is_customer_admin' => 'boolean',
    ];

    // safeAttributes
    protected $safeAttributes = [
        ['name', 'email', 'password', 'online_visibility']
    ];

    protected $validationRules = [
        "email" => [
            "required" => "user.email.required"
        ],
        "online_visibility" => [
            "inconstantarray" => "user.online_visibility.inconstantarray"
        ]
    ];

    /**
     * The attributes that aren't mass assignable.
     *
     * @var array
     */
    protected $guarded = ['attachment_token'];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = [
        'password', 'remember_token', 'deleted_at', 'resettoken', 'validationcode', 'pw_change_validationcode', 'password_cache'
    ];

    protected $actsAsAttachmentTrait_options = [
        'appends' => [
            'avatarURL' => []
        ],
        'inheritSave' => [
            'attachmentData' => [
                'internalName' => 'avatar',
                'unique' => true
            ],
        ]
    ];

    public static function boot()
    {
        parent::boot();

        // after save function
        static::saved(function ($model) {
            $model->generateAttachmentToken();
        });
    }

    public static function current()
    {
        return RequestStorageProvider::get("user.current");
    }

    // sets the current user to the RequestStorage
    public static function setCurrent($user)
    {
        RequestStorageProvider::set("user.current", $user);
    }

    public static function getUserByAttachmentToken($attachmentToken)
    {
        return User::where('attachment_token', $attachmentToken)->first();
    }

    public function getAPIStackInformation()
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'customer' => $this->customer
        ];
    }

    public static function getFilterValues()
    {
        return User::all()->lists('username', 'id')->toArray();
    }

    public function generateAttachmentToken()
    {
        // AUTO-set attachment_token if unsetted
        if ($this->attachment_token == null) {
            // hash of 40 Bytes
            $this->attachment_token = hash("sha1", $this->id . "_" . config('app.api.attachmentTokenSecret') . '_' . $this->customer_id);
            // update the token in DB
            $this->update(['attachment_token' => $this->attachment_token]);
        }
    }

    public function setPasswordAttribute($value)
    {
        $this->attributes['password'] = Hash::make($value);
    }

    public function allowedTo($permissionKey = null, $optionalPermissionData = [])
    {

    }

    public function hasRightTo($rightstring)
    {
        $permissions = $this->permissionListClean();
        $permissions = $permissions['permissions'];
        if (isset($permissions[$rightstring]) && $permissions[$rightstring] == 1) {
            return true;
        } else {
            return false;
        }
    }

    public function hasRightToPermission($rightstring, $user_id)
    {
        $permissions = ObjectPermission::where('object_id', $user_id)->where('object_type', 'user')->get()->first();
        if (count($permissions) > 0) {
            $values = isset($permissions->permissions) ? json_decode($permissions->permissions) : null;
            if ($values != null) {
                if (isset($values->$rightstring) && $values->$rightstring == 1) {
                    return true;
                } else {
                    return false;
                }
            } else {
                return false;
            }
        } else {
            return false;
        }
    }

    public function permissionListClean()
    {
        $permissionListData = ['permissions' => [], 'properties' => []];

        $permissions = Permission::get();

        // create neested permission array
        $neestedPermissions = [];
        foreach ($permissions as $p) {
            $neestedPermissions[$p->name] = true;
        }

        $permissionListData['permissions'] = $neestedPermissions;
        return $permissionListData;
    }

    public function permissionList()
    {
        $permissionListData = ['permissions' => [], 'properties' => []];

        $permissions = Permission::get();

        // create neested permission array
        $neestedPermissions = [];
        foreach ($permissions as $p) {
            $temp = &$neestedPermissions;

            foreach (explode('.', $p->name) as $key) {
                $temp = &$temp[$key];
            }
            // here comes the bool value
            $temp = true;
        }

        $permissionListData['permissions'] = $neestedPermissions;
        return $permissionListData;


        // usergroups will heritade customer rights
        $usergroups = $this->usergroups;

        if (sizeof($usergroups) > 0) {
            foreach ($this->usergroups as $usergroup) {
                $pList = $usergroup->permissionList;
                if ($pList['permissions'] != null) {
                    // merge both arrays, but return only keys, that are valid in abo-permissions
                    $permissionListData['permissions'] = array_merge($permissionListData['permissions'], $pList['permissions']);
                }

                if ($pList['properties'] != null) {
                    $permissionListData['properties'] = array_merge($permissionListData['properties'], $pList['properties']);
                }
            }
        } else {
            // valid customer permissions (permissions => [], properties => [])
            $permissionListData = $this->customer->permissionList;
        }

        // find additional objectPermission
        $objPerm = $this->objectPermission;

        if ($objPerm) {
            $pList = $objPerm->permissionList;
            if ($pList['permissions'] != null) {
                // merge both arrays, but return only keys, that are valid in abo-permissions
                $permissionListData['permissions'] = array_intersect_key($permissionListData['permissions'], $pList['permissions']);
            }

            if ($pList['properties'] != null) {
                $permissionListData['properties'] = array_merge($permissionListData['properties'], $pList['properties']);
            }
        }

        return $permissionListData;
    }


    /*******************
     * MODEL RELATIONS *
     *******************/

    public function usergroups()
    {
        $u2ugIds = $this->user2usergroups->pluck('id')->toArray();

        if (sizeof($u2ugIds) == 0) {
            return [];
        }

        return Usergroup::whereIn('id', $u2ugIds)->get();
    }

    public function user2usergroups()
    {
        return $this->hasMany('App\User2Usergroup');
    }

    public function objectPermission()
    {
        return $this->morphOne('App\ObjectPermission', 'object');
    }

    public function customer()
    {
        return $this->belongsTo('App\Customer');
    }

    public function addressbooks()
    {
        return $this->hasMany('App\Addressbook', "object_id")->where("object_type", "user");
    }

    public function histories()
    {
        return $this->hasMany('App\History');
    }

    public function addresses()
    {
        return $this->morphMany('App\Addressbook', 'object');
    }

    public function assets()
    {
        return $this->hasMany('App\Asset');
    }

    public function tasks()
    {
        return Task::visibleTo($this)->get();
    }


    public function projects()
    {
        return $this->hasMany('App\Project');
    }

    public function folders()
    {
        return $this->hasMany('App\Folder');
    }

    public function products()
    {
        return $this->hasMany('App\Product');
    }

    public function productschemas()
    {
        return $this->hasMany('App\Productschema');
    }

    public function widgets()
    {
        return $this->hasMany('App\Widget');
    }

    public function events()
    {
        return $this->hasMany('App\Event');
    }

    public function pageformats()
    {
        return $this->hasMany('App\Pageformat');
    }

    public function abonnement()
    {
        return $this->belongsTo('App\Abonnement');
    }

    public function company()
    {
        return $this->hasMany('App\Addressbook', 'company');

    }

    public function chatroomfrom()
    {
        return $this->hasMany('App\Chatroom', 'from_user_id', 'id');
    }

    public function chatroomto()
    {
        return $this->hasMany('App\Chatroom', 'to_user_id', 'id');
    }

    public function insertFolderImages($userId, $customerId)
    {

        //set folder images
        $folderArrayImages = array(
            ['name' => 'ROOT', 'image' => ''], //ROOT
            ['name' => 'MANAGE', 'image' => ''], //MANAGE
            ['name' => 'Products', 'image' => 'products.png'], //Products
            ['name' => 'Customers', 'image' => 'customers.png'], //Customers
            ['name' => 'Marketing', 'image' => 'collect-marketing.jpg'],//Marketing
            ['name' => 'Advertising Projects', 'image' => 'projects.png'],//Advertising Projects
            ['name' => 'Design Elements', 'image' => 'design_elements.png'], //Design Elements
            ['name' => 'Frames', 'image' => 'grids.png'], //Frames
            ['name' => 'Tiles', 'image' => 'tiles.png'], //Tiles
            ['name' => 'Backgrounds', 'image' => 'backgrounds.png'], //Backgrounds
            ['name' => 'Images', 'image' => 'image.png'], //Images
            ['name' => 'Text', 'image' => 'text.png'], //Text
            ['name' => 'Shapes', 'image' => 'elements.png'], //Elements
            ['name' => 'Logos', 'image' => 'logos.png'], //Logos
            //['name' => 'Audio', 'image' => 'audio.jpg'], //Audio
            ['name' => 'Video', 'image' => 'video.png'], //Video
            ['name' => 'Documents', 'image' => 'documents.png'], //Documents
            ['name' => 'My elements', 'image' => 'my_elements.png'], //my elements
            ['name' => 'Other data', 'image' => 'collections.png'], //Other data
            ['name' => 'CREATE', 'image' => ''], //CREATE
            ['name' => 'Templates', 'image' => 'customer.jpg'], //Templates
            ['name' => 'Assets', 'image' => 'assets.jpg'], //Assets
            ['name' => 'Photos', 'image' => 'photo.jpg'], //Photos
            ['name' => 'Vectors', 'image' => 'vektor.jpg'], //Vectors
            ['name' => 'SHARE', 'image' => ''], //SHARE
            ['name' => 'Contentbox', 'image' => 'content_box.png'], //Contentbox
            ['name' => 'Inbox', 'image' => 'content_box.png'], //Inbox
            ['name' => 'Outbox', 'image' => 'content_box.png'], //Outbox
        );

        $folderArray = Folder::where('user_id', $userId)->get();
        foreach ($folderArray as $retArray) {
            $folder = Folder::where('id', $retArray->id)->first();

            if ($folder) {
                if ($folder->save()) {
                    foreach ($folderArrayImages as $img) {
                        if ($img['name'] == $retArray->name) {
                            $image = $this->storage_path() . "/" . $img['image'];
                            if (($image)) {
                                $att = [
                                    'size' => filesize($image),
                                    'name' => basename($img['image']),
                                    'tmp_name' => $image
                                ];
                                $folder->saveAttachment($att, ['customer_id' => $customerId, 'user_id' => $userId]);
                            }
                        }

                    }
                }
            }
        }
        //return $folder;
    }

    public function storage_path()
    {
        return storage_path() . "/backgrounds";
    }

    public function setUserPermissions()
    {
        $getPermissions = Permission::all();
        $userPermissionsArray = array();
        foreach ($getPermissions as $permissions) {
            $userPermissions = $permissions['name'];
            $userPermissionsArray[$userPermissions] = true;
        }
        return $userPermissionsArray;

    }

}
