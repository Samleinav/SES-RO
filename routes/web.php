<?php

use Illuminate\Support\Facades\Route;
//use Botble\Dashplugin\Facades\DashFront;

/**
 * new public route for SNS notifications
*/
Route::post('api/sns/notifications', 'Botble\S3MailToMailServer\Http\Controllers\S3MailToMailServerController@handleSNS')
->name('public.sns.notifications');
