<?php return array(
    'root' => array(
        'name' => 'finansfatura/woocommerce-plugin',
        'pretty_version' => 'dev-master',
        'version' => 'dev-master',
        'reference' => '99b634edc6be9b8e0e471ee0f69450889d097e0a',
        'type' => 'wordpress-plugin',
        'install_path' => __DIR__ . '/../../',
        'aliases' => array(),
        'dev' => false,
    ),
    'versions' => array(
        'finansfatura/finansfatura' => array(
            'pretty_version' => 'v2.0.0',
            'version' => '2.0.0.0',
            'reference' => 'bdb7b674c377f5b23ce5578d27cb2aced11f9b78',
            'type' => 'library',
            'install_path' => __DIR__ . '/../finansfatura/finansfatura',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
        'finansfatura/woocommerce-plugin' => array(
            'pretty_version' => 'dev-master',
            'version' => 'dev-master',
            'reference' => '99b634edc6be9b8e0e471ee0f69450889d097e0a',
            'type' => 'wordpress-plugin',
            'install_path' => __DIR__ . '/../../',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
    ),
);
