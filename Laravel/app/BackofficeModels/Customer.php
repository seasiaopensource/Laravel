<?php

namespace App\BackofficeModels;

use Illuminate\Support\Facades\Validator;
use App\AdditionalAboservice;
use App\CustomerAdditionalAboservice;


class Customer extends \App\Customer
{

    public static function boot()
    {
        parent::boot();

        // this is the save beforeFilter
        static::saving(function ($model) {
            $model->handleBeforeSave();
        });

        // this is the save afterFilter
        static::saved(function ($model) {
            $model->handleAfterSave();
        });
    }

    public function handleBeforeSave()
    {
        if ($this->id) {
            $this->prepareAboSwitch();
        }
    }

    public function handleAfterSave()
    {
    }

    public function prepareAboSwitch()
    {
        $customerBeforeSave = self::where('id', $this->id)->first();
        if ($customerBeforeSave->abonnement_id != $this->abonnement_id) {
            CustomerAdditionalAboservice::where('customer_id', $this->id)->delete();
        }
    }

    public function formatBytes()
    {
        $this->max_storage = byteConverter($this->max_storage);
        $this->redeemed_storage = byteConverter($this->redeemed_storage);

        return $this;
    }

    public function aboServices()
    {
        $customeToAboservices = $this->additionalAboservices()->get();
        $aboservices = [];
        foreach ($customeToAboservices as $customeToAboservice) {
            $aboservice = AdditionalAboservice::where('id', $customeToAboservice->additionalAboservice_id)->first();
            $aboservices[] = $aboservice;
        }
        return $aboservices;
    }

    public function additionalAboservices()
    {
        return $this->hasMany('App\CustomerAdditionalAboservice');
    }

    public function updateAboservices($aboServices)
    {

        // if empty remove all aboservices
        if (!$aboServices) {
            CustomerAdditionalAboservice::where('customer_id', $this->id)->delete();
            return true;
        }

        // prepare sorted array of new aboservices
        $sortedServices = [];
        if (is_array($aboServices)) {
            foreach ($aboServices as $key => $value) {
                if (!array_key_exists($value, $sortedServices)) {
                    $sortedServices[$value] = 1;
                } else {
                    $sortedServices[$value] += 1;
                }
            }
        }

        // prepare sorted old aboservices
        $oldAboservices = [];
        foreach ($sortedServices as $key => $value) {
            $oldAboservices[$key] = CustomerAdditionalAboservice::where('customer_id', $this->id)->where('additionalAboservice_id', $key)->count();
        }

        // create or delete difference between old an new aboservices
        foreach ($sortedServices as $key => $value) {
            if ($value > $oldAboservices[$key]) {
                $rounds = $value - $oldAboservices[$key];
                for ($i = 1; $i <= $rounds; $i++) {
                    $abo = new CustomerAdditionalAboservice;
                    $abo->customer_id = $this->id;
                    $abo->additionalAboservice_id = $key;
                    $abo->save();
                }

            }
            if ($value < $oldAboservices[$key]) {
                $diff = $oldAboservices[$key] - $value;
                CustomerAdditionalAboservice::where('customer_id', $this->id)->where('additionalAboservice_id', $key)->limit($diff)->delete();
            }
        }

        // remove all services wich are not included in post
        $aboServices = AdditionalAboservice::get();
        foreach ($aboServices as $aboService) {
            if (!array_key_exists($aboService->id, $sortedServices)) {
                CustomerAdditionalAboservice::where('customer_id', $this->id)->where('additionalAboservice_id', $aboService->id)->delete();
            }
        }

    }

    public function sortAssets()
    {
        $assets = $this->assets;
        $assetsByIndustry = [];
        $assetsByTheme = [];
        if ($assets) {
            foreach ($assets as $asset) {
                if ($asset->free == 0) {
                    $industries = $asset->industries()->get();
                    if ($industries) {
                        foreach ($industries as $industry) {
                            if (!array_key_exists($industry->name, $assetsByIndustry)) {
                                $assetsByIndustry[$industry->name] = 1;
                            } else {
                                $assetsByIndustry[$industry->name] += 1;
                            }
                        }
                    }
                    $assetthemes = $asset->assetthemes()->get();
                    if ($assetthemes) {
                        foreach ($assetthemes as $assettheme) {
                            if (!array_key_exists($assettheme->name, $assetsByTheme)) {
                                $assetsByTheme[$assettheme->name] = 1;
                            } else {
                                $assetsByTheme[$assettheme->name] += 1;
                            }
                        }
                    }
                }
            }
        }
        return $sortedAssets = ['industries' => $assetsByIndustry, 'themes' => $assetsByTheme];
    }

    public function getMaxUsers()
    {
        // todo make jobs to update max users and max storage on create/remove
        $additionalAbos = $this->additionalAboservices()->get();

        $maxUsers = $this->max_users;

        foreach ($additionalAbos as $additionalAbo) {
            $aboservice = AdditionalAboservice::where('id', $additionalAbo->additionalAboservice_id)->first();
            if ($aboservice->name == 'USER5') {
                $maxUsers += 5;
            }
            if ($aboservice->name == 'USER10') {
                $maxUsers += 10;
            }
        }
        return $maxUsers;
    }

}