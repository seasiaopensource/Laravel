<?php

namespace App\Http\ViewComposers;

use Illuminate\View\View;
use App\Providers\RequestStorageProvider;
use App\Helpers\BootstrapHelper;
use App\BackofficeUser;

class AppComposer
{
    /**
     * Bind data to the view.
     *
     * @param  View $view
     * @return void
     */
    public function compose(View $view)
    {
        $view->with("currentUser", BackofficeUser::current());
        $view->with("currentNavigation", RequestStorageProvider::get('app.currentNavigation'));
        #$view->with("bootstrapHelper",new BootstrapHelper);
    }
}