<?php
/**
 * Plugin Name:  UM Field Value Mapper
 * Description:  Mapeo visual de valores de campos Ultimate Member. Permite definir un value de envío distinto al label visible, por formulario y campo, desde el panel de administración.
 * Version:      1.0.2
 * Author:       Labolife
 * Text Domain:  umfvm
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'UMFVM_PATH',   plugin_dir_path( __FILE__ ) );
define( 'UMFVM_URL',    plugin_dir_url( __FILE__ ) );
define( 'UMFVM_OPTION', 'umfvm_mappings' );

require_once UMFVM_PATH . 'includes/class-mapper.php';
require_once UMFVM_PATH . 'admin/class-admin.php';

add_action( 'plugins_loaded',        array( 'UMFVM_Mapper', 'init' ) );
add_action( 'admin_menu',            array( 'UMFVM_Admin',  'add_menu' ) );
add_action( 'admin_enqueue_scripts', array( 'UMFVM_Admin',  'enqueue' ) );

add_action( 'wp_ajax_umfvm_get_fields',     array( 'UMFVM_Admin', 'ajax_get_fields' ) );
add_action( 'wp_ajax_umfvm_save_mapping',   array( 'UMFVM_Admin', 'ajax_save_mapping' ) );
add_action( 'wp_ajax_umfvm_delete_mapping', array( 'UMFVM_Admin', 'ajax_delete_mapping' ) );
