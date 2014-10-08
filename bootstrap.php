<?php

$_haveapi_client_classes = array(
	'Client/Authentication/Base',
	'Client/Authentication/NoAuth',
	'Client/Authentication/Basic',
	'Client/Authentication/Token',
	'Client/Exception/Base',
	'Client/Exception/ActionFailed',
	'Client/Exception/AuthenticationFailed',
	'Client/Action',
	'Client/Resource',
	'Client/ResourceInstance',
	'Client/ResourceInstanceList',
	'Client/Response',
	'Client'
);

foreach($_haveapi_client_classes as $class) {
	include "src/$class.php";
}
