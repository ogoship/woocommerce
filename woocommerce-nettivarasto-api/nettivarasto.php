<?php
/**
 * Plugin Name: WooCommerce OGOShip / Nettivarasto API
 * Plugin URI: https://github.com/ogoship/woocommerce
 * Description: Integrate WooCommerce with Nettivarasto (http://nettivarasto.fi).
 * Author: OGOShip / Nettivarasto
 * Author URI: http://nettivarasto.fi
 * Version: 2.0.0
 * Text Domain: wc-nv-api
 * Domain Path: /i18n/languages/
 *
 * Copyright: (c) 2017 Koivua Oy.
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package   WC-Nettivarasto
 * @author    OGOShip / Nettivarasto.
 * @category  
 * @copyright Copyright (c) 2016, Koivua Oy
 * @license   http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 */


if ( ! defined( 'ABSPATH' ) ) exit; 

require_once 'lib/API.php'; 

class nv_wc_api {
    const name = 'nv_wc_api';
    const slug = 'nv_wc_api';

    private $merchantID = '';
    private $secretToken = '';
    private $api = '';
    private $notice;
    private $error;

    function __construct() {

        register_activation_hook( __FILE__, array( &$this, 'activate_nettivarasto' ) );
        register_deactivation_hook( __FILE__, array( &$this, 'deactivate_nettivarasto' ) );

        $this->merchantID = get_option('woocommerce_nettivarasto_merchant_id');
        $this->secretToken = get_option('woocommerce_nettivarasto_secret_token');
		$this->denyExport = get_option('woocommerce_deny_export_product');
        $this->api = new NettivarastoAPI($this->merchantID, $this->secretToken);
        $this->api->setTimestamp( get_option('nettivarasto_latest_changes_timestamp') );

        add_action( 'init', array( &$this, 'init_nettivarasto' ) );
        add_action('wp_loaded', array( &$this, 'after_wp_load') );

        add_action('get_latest_changes_hook', array( &$this, 'get_latest_changes') );
        add_action('woocommerce_payment_complete', array( &$this, 'save_order_to_nettivarasto') ,10,1);
        add_action('admin_notices', array( &$this, 'show_notice' ), '10');
        add_action( 'woocommerce_order_actions', array( $this, 'add_order_meta_box_actions' ) );
        add_action( 'woocommerce_order_action_nv_send_order_to_nettivarasto', array( $this, 'process_action_nv_send_order_to_nettivarasto' ), 99, 1 );
        remove_action( 'admin_notices', 'woothemes_updater_notice' );

        add_action( 'woocommerce_view_order', array( $this, 'view_order_tracking_code' ), 1 );
        add_action( 'woocommerce_email_order_meta', array( $this, 'email_tracking_code' ), 10, 3 );
    } 
    function activate_nettivarasto() {
      wp_schedule_event( current_time( 'timestamp', true ), 'hourly', 'get_latest_changes_hook' );
    }

    function deactivate_nettivarasto() {
      wp_clear_scheduled_hook( 'get_latest_changes_hook' );
    }

    function init_nettivarasto(){
      global $woocommerce;
      if ( class_exists('Woocommerce') ) {
        $woocommerce->shipping->load_shipping_methods();
        $methods = $woocommerce->shipping->shipping_methods;
        if ($methods) { 
           foreach ($methods as $key => $value) {
            add_filter('woocommerce_shipping_instance_form_fields_'.$key, array( &$this, 'add_nettivarasto_shipping_method_settings') );              }
        }
        add_filter( 'woocommerce_general_settings', array( &$this, 'add_nettivarasto_general_settings' ) ); 
        add_action( 'woocommerce_product_options_general_product_data', array( &$this, 'add_custom_product_general_fields' ) );      
        add_action( 'woocommerce_process_product_meta', array( &$this, 'save_product_meta' ) );
      } 
    }

    function after_wp_load() {   
      if( @$_GET['send_to_nv'] && is_admin() ) {
         do_action('woocommerce_payment_complete', $_GET['post'] );
      }
      if( @$_GET['export_all'] && is_admin()) {
         $this->update_all_products();
      }
      if( @$_GET['get_latest_changes'] && is_admin()) {
          $this->get_latest_changes();
      }
      load_plugin_textdomain('wc-nv-api', false, dirname( plugin_basename( __FILE__ ) ) . '/i18n/languages');
    }

    function add_nettivarasto_shipping_method_settings($fields) {
      $fields['nettivarasto_send'] = array(
                        'title'   => __( 'Transfer to Nettivarasto', 'wc-nv-api' ),
                        'type'    => 'checkbox',
                        'label'   => __( 'Transfer orders made by this shipping method automatically to Nettivarasto.', 'wc-nv-api' ),
                        'default' => 'no',
                        ); 
      $fields['nettivarasto_delivery_type_id'] = array(
                        'title'   => __( 'Delivery Type', 'wc-nv-api' ),
                        'type'    => 'input',
                        'label'   => __( 'Set external code of the default Nettivarasto delivery type for this shipping method.', 'wc-nv-api' ),
                        'default' => '',
                        );   
      return $fields;
    }

  function add_nettivarasto_general_settings( $settings ) {
    $updated_settings = $settings;
    $updated_settings[] = array(
        'name'    => __( 'Nettivarasto General Settings', 'wc-nv-api' ),
        'type'    => 'title',
        'desc'    => __('<p>The following are Nettivarasto general settings.</p><h4>Export</h4><p>Click <a href="?page=wc-settings&export_all=true">here</a> to export all products to Nettivarasto.</p><h4>Update Orders and Products</h4><p>Click <a href="?page=wc-settings&get_latest_changes=true">here</a> to update product and order info from Nettivarasto.</p>', 'wc-nv-api'),
        'id'    => 'nettivarasto_general_settings'
    );
    $updated_settings[] = array(
      'name'      => __( 'Merchant ID', 'wc-nv-api' ),
      'desc_tip'  => __( 'Insert Merchant ID from Nettivarasto', 'wc-nv-api' ),
      'id'        => 'woocommerce_nettivarasto_merchant_id',
      'type'      => 'text',
      'css'       => 'min-width:300px;'
    );
    $updated_settings[] = array(
      'name'      => __( 'Secret Token', 'wc-nv-api' ),
      'desc_tip'  => __( 'Insert Nettivarasto Secret token', 'wc-nv-api' ),
      'id'        => 'woocommerce_nettivarasto_secret_token',
      'type'      => 'text',
      'css'       => 'min-width:300px;'
    );
	 $updated_settings[] = array(
      'name'      => __( 'Deny product export', 'deny-export-product' ),
      'desc_tip'  => __( 'This option will deny the product export to Nettivarasto', 'deny-export-product' ),
      'id'        => 'woocommerce_deny_export_product',
      'type'      => 'checkbox',
      'css'       => 'min-width:300px;'
    );
      
    $updated_settings[] = array( 'type' => 'sectionend', 'id' => 'nettivarasto_general_settings' ); 
    return $updated_settings;
  }

  /**
  * Add fields to product admin
  *
  * @since 1.1.1
  */
  function add_custom_product_general_fields() {
    woocommerce_wp_text_input( 
        array( 
            'id'          => '_nettivarasto_supplier_name', 
            'label'       => __( 'Supplier:', 'wc-nv-api' ), 
            'placeholder' => '',
            'desc_tip'    => 'true',
            'description' => __( 'Enter name of supplier for Nettivarasto.', 'wc-nv-api' ) 
        )
    );
    woocommerce_wp_text_input( 
        array( 
            'id'          => '_nettivarasto_supplier_code', 
            'label'       => __( 'Supplier code:', 'wc-nv-api' ), 
            'placeholder' => '',
            'desc_tip'    => 'true',
            'description' => __( 'Enter code of supplier for Nettivarasto.', 'wc-nv-api' ) 
        )
    );
    woocommerce_wp_text_input( 
        array( 
            'id'          => '_nettivarasto_group', 
            'label'       => __( 'Group:', 'wc-nv-api' ), 
            'placeholder' => '',
            'desc_tip'    => 'true',
            'description' => __( 'Enter group of product for Nettivarasto.', 'wc-nv-api' ) 
        )
    );
    woocommerce_wp_text_input( 
        array( 
            'id'          => '_nettivarasto_purchase_price', 
            'label'       => __( 'Purchase price:', 'wc-nv-api' ), 
            'placeholder' => '',
            'desc_tip'    => 'true',
            'description' => __( 'Enter purchase for Nettivarasto.', 'wc-nv-api' ) 
        )
    );
    woocommerce_wp_checkbox( 
        array( 
            'id'          => '_nettivarasto_no_export', 
            'description' => __( 'Check if you do <strong>not</strong> want to export this product automatically to Nettivarasto.', 'wc-nv-api' ), 
            'label'       => __( 'Do not export to Nettivarasto', 'wc-nv-api' ),
            'cbvalue'     => 'yes'
        )
    );
  }

  /**
  * Save fields from product admin
  *
  * @since 1.1.1
  */
  function save_product_meta($post_id) {
    $nettivarasto_supplier_name = $_POST['_nettivarasto_supplier_name'];
    if( !empty( $nettivarasto_supplier_name ) )
        update_post_meta( $post_id, '_nettivarasto_supplier_name', esc_attr( $nettivarasto_supplier_name ) );

    $nettivarasto_supplier_code = $_POST['_nettivarasto_supplier_code'];
    if( !empty( $nettivarasto_supplier_code ) )
        update_post_meta( $post_id, '_nettivarasto_supplier_code', esc_attr( $nettivarasto_supplier_code ) );

    $nettivarasto_group = $_POST['_nettivarasto_group'];
    if( !empty( $nettivarasto_group ) )
        update_post_meta( $post_id, '_nettivarasto_group', esc_attr( $nettivarasto_group ) );

    $nettivarasto_purchase_price = $_POST['_nettivarasto_purchase_price'];
    if( !empty( $nettivarasto_purchase_price ) )
        update_post_meta( $post_id, '_nettivarasto_purchase_price', esc_attr( $nettivarasto_purchase_price ) );

    $nettivarasto_no_export = $_POST['_nettivarasto_no_export']; 
    update_post_meta( $post_id, '_nettivarasto_no_export', esc_attr( $nettivarasto_no_export ) );
  }


  function save_order_to_nettivarasto($order_id = '') {	
    global $woocommerce;
    $WC_order = new WC_Order($order_id);
    $shipping_methods = $WC_order->get_shipping_methods(); 
    foreach ( $shipping_methods as $shipping_method ) {
      $method_id = ($shipping_method[item_meta][method_id][0]);
    }

    $shipping_method_options = get_option('woocommerce_'.str_replace(':','_',$method_id).'_settings');

      $order = new NettivarastoAPI_Order($this->api,$order_id);
      $nettivarasto_shipping_method=$shipping_method_options['nettivarasto_delivery_type_id'];
      $order_number=$order_id;
      $index = 0;
	  $strTotalProducts =	count($WC_order->get_items());	
      foreach($WC_order->get_items() as $item) {
		  $product=new WC_Product_Variable($id=($item['variation_id'] ? $item['variation_id'] : $item['product_id']));
		  if(( get_post_meta($id, '_nettivarasto_no_export', true) ) == 'yes' && $strTotalProducts==1){
		  		 $WC_order->add_order_note(__('No more product to export this order'), 0);
				 return;
		  }elseif(!( get_post_meta($id, '_nettivarasto_no_export', true) ) == 'yes'){	  
			  $order->setOrderLineCode( $index, ($product->get_sku()) );
			  $order->setOrderLineQuantity( $index, ($item[qty]));
			  $index++;
		  }	  
	   	 
      }

      $order->setPriceTotal($WC_order->order_total);
      $order->setCustomerName($WC_order->shipping_first_name.' '.$WC_order->shipping_last_name);
      $order->setCustomerAddress1($WC_order->shipping_address_1);
      $order->setCustomerAddress2($WC_order->shipping_address_2);
      $order->setCustomerCity($WC_order->shipping_city);
      $order->setCustomerCountry($WC_order->shipping_country);
      $order->setCustomerEmail( get_post_meta($order_id, '_billing_email', true) );
      $order->setCustomerPhone( get_post_meta($order_id, '_billing_phone', true) );
      $order->setCustomerZip($WC_order->shipping_postcode);
	  $order->setComments($WC_order->customer_note);	  
  
      $order->setShipping($nettivarasto_shipping_method);
      if ( $order->save() ) {
          $WC_order->add_order_note(__('Order transferred to Nettivarasto', 'nv-woocommerce-api'), 0);
          $this->notice = 'Order successfully transferred to Nettivarasto.';
          return;
      }
      else {
          $WC_order->add_order_note(__('Error: '.$this->api->getLastError().' <a href="?post='.$WC_order->id.'&action=edit&send_to_nv=true">Send order again.</a>', 'nv-woocommerce-api'), 0);
          wp_mail( get_option( 'admin_email' ), 'Error - Nettivarasto API', $this->api->getLastError() ); 
      return; 
      }    
   /*}*/
  }


  function get_all_products() {
      $products = $this->api->getAllProducts();
  }
  
  function delete_all_products() {
      $products = $this->api->getAllProducts();
      foreach ( $products['Response']['Product'] as $product) {
          $NV_product = new NettivarastoAPI_Product($this->api, $product['Code']);
          $NV_product->delete();
      }
  }
  
  function update_all_products() {
	if($this->denyExport=="yes"){
		$this->notice = 'Product export denied in the Nettivarasto settings.';
		return;
	}

    //Let's check if we have WPML
    global $sitepress;
    if ( $sitepress ) {
      //Let's switch to default site language to make sure we don't mix languages
      $original_language = ICL_LANGUAGE_CODE;
      $default_language = $sitepress->get_default_language();
      $sitepress->switch_lang( $default_language );
    }
     
    global $woocommerce;
  
    if ( $sitepress ) {
      //Suppress filters in order to let WPML alter query
      $args    = array( 'post_type' => 'product', 'posts_per_page' => -1, 'suppress_filters' => false );
    } else {
      $args     = array( 'post_type' => 'product', 'posts_per_page' => -1 );
    }
     
    $products = get_posts( $args );
    $NV_products = array('Products' => 
      array( 
          'Product' => array()
          )
      );
	$_tax = new WC_Tax();
    foreach ($products as $product) {
      $WC_product = get_product($product->ID);
      //We only allow export of simple and variable products. 
      if( !( get_post_meta($WC_product->id, '_nettivarasto_no_export', true) ) == 'yes' && ( $WC_product->product_type == 'simple' || $WC_product->product_type == 'variable' ) )  {
        if ( $WC_product->has_child() ) {
          $children = $WC_product->get_children();
          foreach ( $children as $child ) {
            $WC_child_product = get_product($child);
            $variations = implode( $WC_child_product->get_variation_attributes(), ',' );
            $PictureUrl = wp_get_attachment_image_src( $WC_child_product->get_image_id(), 'shop_thumbnail' );
            
            //Declare array here in order to add conditional 
			$product_array = array(
				'Code' => $WC_child_product->get_sku(),
				'Name' => $product->post_title.' ('.$variations.')',
				'Description' => strip_tags($WC_child_product->post_content),
				'InfoUrl' => get_permalink($WC_child_product->id),
				'SalesPrice' => $WC_child_product->get_price_including_tax(),
				'Weight'=> $WC_product->get_weight(),
				'Height'=> $WC_product->get_height(),
				'Width'=> $WC_product->get_width(),
				'Depth'=> $WC_product->get_length(),
				'VatPercentage'=> $tax,
				'Currency' => get_woocommerce_currency()
	          );
            //Nettivarasto does not handle well null values, so we add keys to array only if they have values
            if ( $PictureUrl ) $product_array['PictureUrl'] = $PictureUrl;
            if ( get_post_meta($WC_product->id, '_nettivarasto_supplier_name', true) ) $product_array['Supplier'] = get_post_meta($WC_product->id, '_nettivarasto_supplier_name', true);
            if ( get_post_meta($WC_product->id, '_nettivarasto_supplier_code', true) ) $product_array['SupplierCode'] = get_post_meta($WC_product->id, '_nettivarasto_supplier_code', true);
            if ( get_post_meta($WC_product->id, '_nettivarasto_group', true) ) $product_array['Group'] = get_post_meta($WC_product->id, '_nettivarasto_group', true);
            if ( get_post_meta($WC_product->id, '_nettivarasto_purchase_price', true) ) $product_array['Price'] = get_post_meta($WC_product->id, '_nettivarasto_purchase_price', true);
            //Add product array to array
            $NV_products['Products']['Product'][] = $product_array;
            //Reset variables just in case
            $product_array = '';
            $PictureUrl = '';
            $variations = ''; 
          }
        }
  
        else {
          $PictureUrl = wp_get_attachment_image_src( $WC_product->get_image_id(), 'shop_thumbnail' );
          $product_array = array(
            'Code' => $WC_product->get_sku(),
            'Name' => $product->post_title,
            'Description' => strip_tags($product->post_content),
            'InfoUrl' => get_permalink($WC_product->id),
            'SalesPrice' => $WC_product->get_price_including_tax(),
			'Weight'=> $WC_product->get_weight(),
			'Height'=> $WC_product->get_height(),
			'Width'=> $WC_product->get_width(),
			'Depth'=> $WC_product->get_length(),
			'VatPercentage'=> $tax,
            'Currency' => get_woocommerce_currency()
          );
          if ( get_post_meta($WC_product->id, '_nettivarasto_supplier_name', true) ) $product_array['Supplier'] = get_post_meta($WC_product->id, '_nettivarasto_supplier_name', true);
          if ( get_post_meta($WC_product->id, '_nettivarasto_supplier_code', true) ) $product_array['SupplierCode'] = get_post_meta($WC_product->id, '_nettivarasto_supplier_code', true);
          if ( get_post_meta($WC_product->id, '_nettivarasto_group', true) ) $product_array['Group'] = get_post_meta($WC_product->id, '_nettivarasto_group', true);
          if ( get_post_meta($WC_product->id, '_nettivarasto_purchase_price', true) ) $product_array['Price'] = get_post_meta($WC_product->id, '_nettivarasto_purchase_price', true);
          if ( $PictureUrl ) $product_array['PictureUrl'] = $PictureUrl;
          $NV_products['Products']['Product'][] = $product_array;
        }
        //Reset variables just in case
        $product_array = '';
        $PictureUrl = '';
        $variations = '';
      } 
    }  
	//exit;
    if ( $sitepress ) {
      //Let's switch back to original language
      $sitepress->switch_lang( $original_language );
    }
    $response = $this->api->updateAllProducts($NV_products);
    if ( $response ) {
      if ( ! ( (string)$response['Response']['Info']['@Success'] == 'true' ) ) {
         $this->error = $response['Response']['Info']['@Error'];
      } else {
        $this->notice = 'Product export completed.';
      }
    }
  }
  
  function set_notice($notice) {
    $this->notice = $notice;
  }

  function get_notice() {
    return $this->notice;
  }

  function set_error($error) {
    $this->error = $error;
  }

  function get_error() {
    return $this->error;
  }

  function show_notice() {
    if($this->notice) {
      ?>
      <div class="updated">
          <p><?php echo '<strong>Nettivarasto:</strong> '.$this->notice; ?></p>
      </div>
      <?php
    }
    $this->notice = '';
    if($this->error) {
      ?>
      <div class="error">
          <p><?php echo '<strong>Nettivarasto:</strong> '.$this->error; ?></p>
      </div>
      <?php
    }
    $this->error = '';
  }

  function add_order_meta_box_actions($order) {
    $actions['nv_send_order_to_nettivarasto'] = __( 'Send Order to Nettivarasto', 'nv-woocommerce-api' );
    return $actions;
  }
  
  function process_action_nv_send_order_to_nettivarasto( $order ) {
    $this->save_order_to_nettivarasto($order->id);
  }

  function get_latest_changes() {
    global $woocommerce;
    global $woocommerce_wpml;
  
    $latest = $this->api->latestChanges($latestProducts, $latestOrders);
      
    if($latestOrders) {
        foreach($latestOrders as $latestOrder) {
                          if( get_post_status ( $latestOrder->getReference() ) ) {
                $WC_order = new WC_order( $latestOrder->getReference() );
                switch ( $latestOrder->getStatus() ) {
                    case  'SHIPPED': 
                        update_post_meta( $latestOrder->getReference(), 'nettivarasto_tracking', $latestOrder->getTrackingNumber() );
                        $WC_order->add_order_note(__('Nettivarasto change of status to SHIPPED. ', 'nv-woocommerce-api'), 0);
                        $WC_order->add_order_note(__('Tracking number: ', 'nv-woocommerce-api').$latestOrder->getTrackingNumber(), 0);
                        $WC_order->update_status('completed');
                        break;
                    case  'CANCELLED':
                        $WC_order->add_order_note(__('Nettivarasto change of status to CANCELLED.', 'nv-woocommerce-api'), 0);
                        break;
                    case  'COLLECTING':
                        $WC_order->add_order_note(__('Nettivarasto change of status to COLLECTING.', 'nv-woocommerce-api'), 0);
                        break;
                    case  'PENDING':
                        $WC_order->add_order_note(__('Nettivarasto change of status to PENDING.', 'nv-woocommerce-api'), 0);
                        break;
                    case  'RESERVED':
                        $WC_order->add_order_note(__('Nettivarasto change of status to RESERVED.', 'nv-woocommerce-api'), 0);
                        break;
                }
            }
        }
    }

    if($latestProducts) {
      foreach($latestProducts as $latestProduct) {
          $WC_product = $this->get_product_by_sku( $latestProduct->getCode() );
              if($WC_product) {
                  $WC_product->set_stock( $latestProduct->getStock(), 'set' );
                  if ( $woocommerce_wpml ) {
                    $woocommerce_wpml->products->sync_post_action( $WC_product->id, $WC_product );
                  }
                  if ( $latestProduct->getStock() ) {
                  $WC_product->set_stock_status( 'instock' );
              }
           }
      }
    }
    update_option('nettivarasto_latest_changes_timestamp', $this->api->getTimestamp());
    $this->notice = __('Product and order data updated from Nettivarasto.', 'nv-woocommerce-api');
  }
  
  function get_product_by_sku( $sku ) {
      global $wpdb;
      $product_id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key='_sku' AND meta_value='%s' LIMIT 1", $sku ) );
      if ( $product_id ) return get_product( $product_id );
      return null;
  }

  /**
  * Display the tracking code on the 'order completed' email
  *
  * @since 1.0.4
  */
  public function email_tracking_code( $order, $sent_to_admin, $plain_text ) {
    if ( $plain_text ) {
      //Do this if we have a plain email
      $tracking_code = get_post_meta( $order->id, 'nettivarasto_tracking', true );
      if($tracking_code) {
        echo "\n".__('Tracking code:').' '.$tracking_code."\n";
      }
    } else {
      //Do this if we have a normal email
      $tracking_code = get_post_meta( $order->id, 'nettivarasto_tracking', true );
      if($tracking_code) {
        echo '<div>';
          echo '<h3>'.__('Track Your Shipment', 'wc-nv-api').'</h3>';
          echo '<p>'.__('Tracking code:', 'wc-nv-api').' '.$tracking_code.'</p>';
        echo '</div>';
      }
    }
  }

  /**
  * Display the tracking code on the 'view-order.php' template
  *
  * @since 1.0.4
  */
  public function view_order_tracking_code( $order_id ) {
    $tracking_code = get_post_meta( $order_id, 'nettivarasto_tracking', true );
    if($tracking_code) {
      echo '<div>';
        echo '<h3>'.__('Track Your Shipment', 'wc-nv-api').'</h3>';
        echo '<p>'.__('Tracking code:', 'wc-nv-api').' '.$tracking_code.'</p>';
      echo '</div>';
    }
  }
}

$nv_wc_api = new nv_wc_api();