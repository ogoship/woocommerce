<?php
/**
 * Plugin Name: OGOShip API for WooCommerce
 * Plugin URI: https://github.com/ogoship/woocommerce
 * Description: Integrate WooCommerce with OGOship / Nettivarasto (https://ogoship.com).
 * Author: OGOShip
 * Author URI: https://www.ogoship.com
 * Version: 3.3.6
 * Text Domain: ogoship-nettivarasto-api-for-woocommerce
 * Domain Path: /i18n/languages/
 * WC requires at least: 3.0.0
 * WC tested up to: 3.5.4
 *
 * Copyright: (c) 2018 Koivua Oy.
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package   WC-OGOship
 * @author    OGOShip
 * @copyright Copyright (c) 2018, Koivua Oy
 * @license   http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 */


if ( ! defined( 'ABSPATH' ) ) exit; 

require_once 'lib/API.php'; 

class nv_wc_api {

    private $merchantID = '';
    private $secretToken = '';
    private $api = '';
    private $notice;
    private $error;
    private $version;

    function __construct() {

        register_activation_hook( __FILE__, array( &$this, 'check_latest_changes_hook' ) );
        register_deactivation_hook( __FILE__, array( &$this, 'deactivate_nettivarasto' ) );

        $this->merchantID = get_option('woocommerce_nettivarasto_merchant_id');
        $this->secretToken = get_option('woocommerce_nettivarasto_secret_token');
		$this->denyExport = get_option('woocommerce_deny_export_product');
		$this->checkDuplicateSKU = get_option('woocommerce_check_duplicate_sku');

        $this->version = "Woocommerce 3.3.X";
        if ( is_admin() ) {
            if( !function_exists('get_plugin_data') ){
                require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
                $pluginfo = get_plugin_data(__FILE__, false, false);
                if(isset($pluginfo) && is_array($pluginfo) && isset($pluginfo["Version"])) {
                    $this->version = "Woocommerce " . $pluginfo["Version"];
                }
            }
        }

        $this->api = new NettivarastoAPI($this->merchantID, $this->secretToken, $this->version);
        $this->api->setTimestamp( get_option('nettivarasto_latest_changes_timestamp') );

        add_action( 'init', array( &$this, 'init_nettivarasto' ) );
        add_action('wp_loaded', array( &$this, 'after_wp_load') );

        add_action('get_latest_changes_hook', array( &$this, 'get_latest_changes') );
        add_action('woocommerce_payment_complete', array( &$this, 'save_order_to_nettivarasto') ,10,1);
        add_action('admin_notices', array( &$this, 'show_notice' ), '10');
        add_action( 'woocommerce_order_actions', array( $this, 'add_order_meta_box_actions' ) );
        add_action( 'woocommerce_order_action_nv_send_order_to_nettivarasto', array( $this, 'process_action_nv_send_order_to_nettivarasto' ), 99, 1 );
        //remove_action( 'admin_notices', 'woothemes_updater_notice' );

        add_action( 'woocommerce_view_order', array( $this, 'view_order_tracking_code' ), 1 );
        add_action( 'woocommerce_email_order_meta', array( $this, 'email_tracking_code' ), 10, 3 );
        add_action('woocommerce_settings_saved', array($this, 'check_latest_changes_hook'));
    } 
    function activate_nettivarasto() {
      wp_schedule_event( current_time( 'timestamp', true ), 'hourly', 'get_latest_changes_hook' );
    }

    function deactivate_nettivarasto() {
      wp_clear_scheduled_hook( 'get_latest_changes_hook' );
    }

    function check_latest_changes_hook()
    {
        if(get_option('woocommerce_nettivarasto_hourly_updates') != 'yes')
        {
            if(wp_next_scheduled( 'get_latest_changes_hook' ))
            {
                $this->deactivate_nettivarasto();
            }
        } else {
            if(!wp_next_scheduled( 'get_latest_changes_hook' ))
            {
                $this->activate_nettivarasto();
            }
        }
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
	  if( @$_GET['sku_duplicate'] && is_admin()) {
		$products = $this->get_all_duplicate_sku_products();
		
		$productsArray = array();
		
		foreach($products['Products'] as $key=> $value){
			if(!empty($value['Code'])){
				$productsArray[] = $value['Code'];
			}
		}	 

		$arr_unique = array_unique($productsArray);
		$arr_duplicates = array_diff_assoc($productsArray, $arr_unique);
		?>
		<style>
			.tableClass {
			  border: 1px solid #ccc;
			  border-collapse: collapse;
			  margin: 0;
			  padding: 0;
			  width: 100%;
			  table-layout: fixed;
			}			
			.tableClass tr {
			  background: #f8f8f8;
			  border: 1px solid #ddd;
			  padding: .35em;
			}
			.tableClass th,
			.tableClass td {
			  padding: .625em;
			  text-align: center;
			}
			.tableClass th {
			  font-size: .85em;
			  letter-spacing: .1em;
			  text-transform: uppercase;
			}
			@media screen and (max-width: 600px) {
			  .tableClass {
				border: 0;
			  }
			  .tableClass thead {
				border: none;
				clip: rect(0 0 0 0);
				height: 1px;
				margin: -1px;
				overflow: hidden;
				padding: 0;
				position: absolute;
				width: 1px;
			  }
			  .tableClass tr {
				border-bottom: 3px solid #ddd;
				display: block;
				margin-bottom: .625em;
			  }
			  .tableClass td {
				border-bottom: 1px solid #ddd;
				display: block;
				font-size: .8em;
				text-align: right;
			  }
			  .tableClass td:before {
				content: attr(data-label);
				float: left;
				font-weight: bold;
				text-transform: uppercase;
			  }
			  .tableClass td:last-child {
				border-bottom: 0;
			  }
			}
			.overlay {
			  position: fixed;
			  top: 0;
			  bottom: 0;
			  left: 0;
			  right: 0;
			  background: rgba(0, 0, 0, 0.7);
			  transition: opacity 500ms;
			  visibility: hidden;
			  opacity: 0;
			  z-index: 999;
			}
			.overlay:target {
			  visibility: visible;
			  opacity: 1;
			}

			.popup {
			  margin: 70px auto;
			  padding: 20px;
			  background: #fff;
			  border-radius: 5px;
			  width: 30%;
			  position: relative;
			  transition: all 5s ease-in-out;
			}
			.popup h2 {
			  margin-top: 0;
			  color: #333;
			  font-family: Tahoma, Arial, sans-serif;
			}
			.popup .close {
			  position: absolute;
			  top: 20px;
			  right: 30px;
			  transition: all 200ms;
			  font-size: 30px;
			  font-weight: bold;
			  text-decoration: none;
			  color: #333;
			}
			.popup .close:hover {
			  color: #06D85F;
			}
			.popup .content {
			  max-height: 50%;
			  overflow: auto;
			}
			@media screen and (max-width: 700px){
			  .popup{
				width: 70%;
			  }
			}
		</style>
		 
		 <div id="popup1" class="overlay">
			<div class="popup">
				<h2>Duplicate SKU Products</h2>
				<a class="close" href="#">&times;</a>
				<div class="content">
					<table class="tableClass">
						<tr>
							<th scope="col">S.No</th>
							<th scope="col">Product Name</th>
							<th scope="col">SKU</th>
						</tr>
					<?php $val = 1;
						foreach($products['Products'] as $prodKey=> $results){
							
							foreach($arr_duplicates as $key => $values){ 
								
								if(isset($results['Code']) && $results['Code']!='' && $results['Code'] == $values){ 
							?>						
							<tr>
								<td><?php echo $val; ?></td>
								<td><a href="post.php?post=<?php echo $results['ID']; ?>&action=edit" target="_blank"><?php echo $results['Name']; ?></a></td>
								<td><?php echo $results['Code']; ?></td>
							</tr>
							<?php $val++;
						 } } } ?>
					</table>
				</div><br /><br />
				<h2>Empty SKU Products</h2>
				<div class="content">
					<table class="tableClass">
						<tr>
							<th scope="col">S.No</th>
							<th scope="col">Product Name</th>
							<th scope="col">SKU</th>
						</tr>
					<?php $val = 1;
						foreach($products['EmptyProducts'] as $prodKey=> $results){
							
							?>						
							<tr>
								<td><?php echo $val; ?></td>
								<td><a href="post.php?post=<?php echo $results['ID']; ?>&action=edit" target="_blank"><?php echo $results['Name']; ?></a></td>
								<td><?php echo $results['Code']; ?></td>
							</tr>
							<?php $val++;
							} ?>
					</table>
				</div>
			</div>
		</div>
		<?php 
      }
      if( @$_GET['get_latest_changes'] && is_admin()) {
          $this->get_latest_changes();
      }
      load_plugin_textdomain('ogoship-nettivarasto-api-for-woocommerce', false, dirname( plugin_basename( __FILE__ ) ) . '/i18n/languages');
    }

    function add_nettivarasto_shipping_method_settings($fields) {
      $fields['nettivarasto_send'] = array(
                        'title'   => __( 'Transfer to OGOship', 'ogoship-nettivarasto-api-for-woocommerce' ),
                        'type'    => 'checkbox',
                        'label'   => __( 'Transfer orders made by this shipping method automatically to OGOship.', 'ogoship-nettivarasto-api-for-woocommerce' ),
                        'default' => 'no',
                        ); 
      $fields['nettivarasto_delivery_type_id'] = array(
                        'title'   => __( 'OGOship Delivery Type', 'ogoship-nettivarasto-api-for-woocommerce' ),
                        'type'    => 'input',
                        'desc_tip'   => __( 'Set external code of the OGOship delivery type for this shipping method.', 'ogoship-nettivarasto-api-for-woocommerce' ),
                        'default' => '',
                        );   
      return $fields;
    }

  function add_nettivarasto_general_settings( $settings ) {
    $updated_settings = $settings;
    $updated_settings[] = array(
        'name'    => __( 'OGOship General Settings', 'ogoship-nettivarasto-api-for-woocommerce' ),
        'type'    => 'title',
        'desc'    => '<p>'.__('The following are OGOship general settings.', 'ogoship-nettivarasto-api-for-woocommerce').'</p><h4>'
		.__('Export', 'ogoship-nettivarasto-api-for-woocommerce').'</h4><p><a href="?page=wc-settings&export_all=true">'
		.__('Click here', 'ogoship-nettivarasto-api-for-woocommerce').'</a> '.__('to export all products to Nettivarasto', 'ogoship-nettivarasto-api-for-woocommerce')
		.'.</p><h4>'.__('Update Orders and Products', 'ogoship-nettivarasto-api-for-woocommerce')
		.'</h4><p><a href="?page=wc-settings&get_latest_changes=true">'.__('Click here', 'ogoship-nettivarasto-api-for-woocommerce')
		.'</a> '.__('to update product and order info from OGOship', 'ogoship-nettivarasto-api-for-woocommerce').'.</p>
		<h4>'.__('Check duplicate and empty SKUs', 'ogoship-nettivarasto-api-for-woocommerce').'</h4>
		<p><a href="?page=wc-settings&sku_duplicate=true#popup1">'.__('Click here', 'ogoship-nettivarasto-api-for-woocommerce')
		.'</a> '.__('to view the duplicate and empty sku products', 'ogoship-nettivarasto-api-for-woocommerce').'.</p>',
        'id'    => 'nettivarasto_general_settings'
    );
	$timestampstr = __('never', 'ogoship-nettivarasto-api-for-woocommerce');
	if(get_option('nettivarasto_latest_changes_timestamp'))
	{
	    $tsdate = new DateTime();
	    $tsdate->setTimestamp(get_option('nettivarasto_latest_changes_timestamp'));
	    try {
		$tsdate->setTimezone(new DateTimeZone(get_option('timezone_string', "UTC")));
	    } catch(Exception $e) {}

	    $timestampstr = $tsdate->format('Y-m-d H:i:s');
	}
    $updated_settings[] = array(
        'type'    => 'title',
        'desc'    => __('Latest successful update from OGOship', 'ogoship-nettivarasto-api-for-woocommerce') . ': ' . $timestampstr,
        'id'    => 'nettivarasto_latest_success_time'
    );

    $updated_settings[] = array(
      'name'      => __( 'Merchant ID', 'ogoship-nettivarasto-api-for-woocommerce' ),
      'desc_tip'  => __( 'Insert Merchant ID from OGOship', 'ogoship-nettivarasto-api-for-woocommerce' ),
      'id'        => 'woocommerce_nettivarasto_merchant_id',
      'type'      => 'text',
      'css'       => 'min-width:300px;'
    );
    $updated_settings[] = array(
      'name'      => __( 'Secret Token', 'ogoship-nettivarasto-api-for-woocommerce' ),
      'desc_tip'  => __( 'Insert OGOship Secret token', 'ogoship-nettivarasto-api-for-woocommerce' ),
      'id'        => 'woocommerce_nettivarasto_secret_token',
      'type'      => 'text',
      'css'       => 'min-width:300px;'
    );
	 $updated_settings[] = array(
      'name'      => __( 'Deny product export', 'ogoship-nettivarasto-api-for-woocommerce' ),
      'desc_tip'  => __( 'This option will deny the product export to OGOship', 'ogoship-nettivarasto-api-for-woocommerce' ),
      'id'        => 'woocommerce_deny_export_product',
      'type'      => 'checkbox',
      'css'       => 'min-width:300px;'
    );
	$updated_settings[] = array(
      'name'      => __( 'Hourly order status and product stock updates on/off', 'ogoship-nettivarasto-api-for-woocommerce' ),
      'desc_tip'  => __( 'Check to enable automatic hourly retrieval of latest order status and product stock level changes.', 'ogoship-nettivarasto-api-for-woocommerce' ),
      'id'        => 'woocommerce_nettivarasto_hourly_updates',
      'type'      => 'checkbox',
      'default'   => 'no',
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
	global $product_object;
	$getSkuValue = $product_object->get_sku( 'edit' );
	
    woocommerce_wp_text_input( 
        array( 
            'id'          => '_nettivarasto_supplier_name', 
            'label'       => __( 'Supplier:', 'ogoship-nettivarasto-api-for-woocommerce' ), 
            'placeholder' => '',
            'desc_tip'    => 'true',
            'description' => __( 'Enter name of supplier for OGOship.', 'ogoship-nettivarasto-api-for-woocommerce' ) 
        )
    );
    woocommerce_wp_text_input( 
        array( 
            'id'          => '_nettivarasto_supplier_code', 
            'label'       => __( 'Supplier code:', 'ogoship-nettivarasto-api-for-woocommerce' ), 
            'placeholder' => '',
            'desc_tip'    => 'true',
            'description' => __( 'Enter code of supplier for OGOship.', 'ogoship-nettivarasto-api-for-woocommerce' ) 
        )
    );
    woocommerce_wp_text_input( 
        array( 
            'id'          => '_nettivarasto_group', 
            'label'       => __( 'Group:', 'ogoship-nettivarasto-api-for-woocommerce' ), 
            'placeholder' => '',
            'desc_tip'    => 'true',
            'description' => __( 'Enter group of product for OGOship.', 'ogoship-nettivarasto-api-for-woocommerce' ) 
        )
    );
    woocommerce_wp_text_input( 
        array( 
            'id'          => '_nettivarasto_purchase_price', 
            'label'       => __( 'Purchase price:', 'ogoship-nettivarasto-api-for-woocommerce' ), 
            'placeholder' => '',
            'desc_tip'    => 'true',
            'description' => __( 'Enter purchase price for OGOship.', 'ogoship-nettivarasto-api-for-woocommerce' ) 
        )
    );
	
	if(empty($getSkuValue)){ ?>
		<script type="text/javascript">
		jQuery(document).ready(function(){
			jQuery('#_nettivarasto_no_export').prop('checked', true);
		})
		</script>
	<?php } ?>
	<?php
	
    woocommerce_wp_checkbox( 
        array( 
            'id'          => '_nettivarasto_no_export', 
            'description' => __( 'Check if you do <strong>not</strong> want to export this product automatically to OGOship.', 'ogoship-nettivarasto-api-for-woocommerce' ), 
            'label'       => __( 'Do not export to OGOship', 'ogoship-nettivarasto-api-for-woocommerce' ),
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
        $method_id = $shipping_method->get_method_id();
        // WC 3.4 ->
        if(strpos($method_id, ':') === FALSE)
        {
            $method_id = $shipping_method->get_method_id() . "_" . $shipping_method->get_instance_id();
        }
    }
	

    $shipping_method_options = get_option('woocommerce_'.str_replace(':','_',$method_id).'_settings');
	
      $key = $WC_order->get_order_key();
      $key = str_replace('wc_order_', '', $key);
      $order = new NettivarastoAPI_Order($this->api, $order_id . '-' . $key);
      $nettivarasto_shipping_method=$shipping_method_options['nettivarasto_delivery_type_id'];
      $index = 0;
	  $strTotalProducts =	count($WC_order->get_items());
      foreach($WC_order->get_items() as $item) {
        if($item['variation_id']){
          $id = $item['variation_id'];
          $product=new WC_Product_Variation($id);
        } else {
          $id = $item['product_id'];
          $product=wc_get_product($id);
        }
        if(!( get_post_meta($id, '_nettivarasto_no_export', true) ) == 'yes'){	  
			  $order->setOrderLineCode( $index, ($product->get_sku()) );
			  $order->setOrderLineQuantity( $index, ($item['qty']));
			  $order->setOrderLinePrice( $index, $product->get_price());
			  $index++;
		  }
	   	 
      }
      if($index == 0){
        $WC_order->add_order_note(__('No products in order to export', 'ogoship-nettivarasto-api-for-woocommerce'), 0);
        return;
      }

      $order->setPriceTotal($WC_order->get_total());
	  $order->setPriceCurrency($WC_order->get_currency());
      $order->setCustomerName($WC_order->get_shipping_first_name().' '.$WC_order->get_shipping_last_name());
      $order->setCustomerAddress1($WC_order->get_shipping_address_1());
      $order->setCustomerAddress2($WC_order->get_shipping_address_2());
      $order->setCustomerCity($WC_order->get_shipping_city());
      $order->setCustomerCountry($WC_order->get_shipping_country());
      $order->setCustomerEmail( get_post_meta($order_id, '_billing_email', true) );
      $order->setCustomerPhone( get_post_meta($order_id, '_billing_phone', true) );
      $order->setCustomerZip($WC_order->get_shipping_postcode());
      $order->setComments($WC_order->get_customer_note());	  
      $pupcode = get_post_meta($order_id, '_woo_carrier_agent_id', true);
      if(trim($pupcode) != "") {
        $order->setPickUpPointCode(trim($pupcode));
	  }
  
      $order->setShipping($nettivarasto_shipping_method);
      if ( $order->save() ) {
          $WC_order->add_order_note(__('Order transferred to OGOship', 'ogoship-nettivarasto-api-for-woocommerce'), 0);
          $this->notice = __('Order transferred to OGOship', 'ogoship-nettivarasto-api-for-woocommerce');
          return;
      }
      else {
          $WC_order->add_order_note(__('Error', 'ogoship-nettivarasto-api-for-woocommerce').': '.$this->api->getLastError().' <a href="?post='
			. $WC_order->get_id().'&action=edit&send_to_nv=true">'.__('Send order again.', 'ogoship-nettivarasto-api-for-woocommerce').'</a>', 0);
          wp_mail( get_option( 'admin_email' ), 'Error - OGOship API', $this->api->getLastError() ); 
      return; 
      }    

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
  
  function get_all_duplicate_sku_products() {  
	global $wpdb;
	$args = array( 'post_type' => 'product', 'posts_per_page' => -1 );
	$products = get_posts( $args );
		
	$NV_products = array('Products' => 
		array( 
			'Product' => array()
		)
	);

	foreach ($products as $product) {
		$WC_product = wc_get_product($product->ID);
	  
		$productSku = $WC_product->get_sku();
	
		if(!empty($productSku)){
			$product_array = array(
				'ID' => $WC_product->get_ID(),
				'Code' => $WC_product->get_sku(),
				'Name' => $WC_product->get_name(),
				'Description' => strip_tags($WC_product->get_description()),
				'InfoUrl' => get_permalink($WC_product->get_id()),
				'SalesPrice' => wc_get_price_including_tax($WC_product),
				'Weight'=> $WC_product->get_weight(),
				'Height'=> $WC_product->get_height(),
				'Width'=> $WC_product->get_width(),
				'Depth'=> $WC_product->get_length(),
				'Permalink'=> $WC_product->get_permalink(),
				//'VatPercentage'=> $_tax->get_rate_percent($WC_product->get_tax_class()),
				'Currency' => get_woocommerce_currency()
			);
			$NV_products['Products'][] = $product_array;
		}else{
			$product_array = array(
				'ID' => $WC_product->get_ID(),
				'Code' => $WC_product->get_sku(),
				'Name' => $WC_product->get_name(),
				'Description' => strip_tags($WC_product->get_description()),
				'InfoUrl' => get_permalink($WC_product->get_id()),
				'SalesPrice' => wc_get_price_including_tax($WC_product),
				'Weight'=> $WC_product->get_weight(),
				'Height'=> $WC_product->get_height(),
				'Width'=> $WC_product->get_width(),
				'Depth'=> $WC_product->get_length(),
				'Permalink'=> $WC_product->get_permalink(),
				//'VatPercentage'=> $_tax->get_rate_percent($WC_product->get_tax_class()),
				'Currency' => get_woocommerce_currency()
			);
			$NV_products['EmptyProducts'][] = $product_array;
		}
		
	}
	return $NV_products;
  }
  
  function update_all_products() {
	if($this->denyExport=="yes"){
		$this->notice = __('Product export denied in the OGOship settings.', 'ogoship-nettivarasto-api-for-woocommerce');
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
      $WC_product = wc_get_product($product->ID);
	  
      //We only allow export of simple and variable products. 
      if( !( get_post_meta($WC_product->get_id(), '_nettivarasto_no_export', true) ) == 'yes' && ( $WC_product->get_type() == 'simple' || $WC_product->get_type() == 'variable' ) )  {
        if ( $WC_product->has_child() ) {
          $children = $WC_product->get_children();
          foreach ( $children as $child ) {
            $WC_child_product = wc_get_product($child);
            $variations = implode( $WC_child_product->get_variation_attributes(), ',' );
            $PictureUrl = wp_get_attachment_image_src( $WC_child_product->get_image_id(), 'shop_thumbnail' );
            
            //Declare array here in order to add conditional 
			$productSku = $WC_product->get_sku();
			if(!empty($productSku)){
			$product_array = array(
				'Code' => $WC_child_product->get_sku(),
				'Name' => $WC_child_product->get_name().' ('.$variations.')',
				'Description' => strip_tags($WC_child_product->get_description()),
				'InfoUrl' => get_permalink($WC_child_product->get_id()),
				'SalesPrice' => wc_get_price_including_tax($WC_child_product),
				'Weight'=> $WC_product->get_weight(),
				'Height'=> $WC_product->get_height(),
				'Width'=> $WC_product->get_width(),
				'Depth'=> $WC_product->get_length(),
				'VatPercentage'=> $_tax->get_rate_percent($WC_child_product->get_tax_class()),
				'Currency' => get_woocommerce_currency()
	          );
            //Nettivarasto does not handle well null values, so we add keys to array only if they have values
            if ( $PictureUrl ) $product_array['PictureUrl'] = $PictureUrl;
            if ( get_post_meta($WC_product->get_id(), '_nettivarasto_supplier_name', true) ) $product_array['Supplier'] = get_post_meta($WC_product->get_id(), '_nettivarasto_supplier_name', true);
            if ( get_post_meta($WC_product->get_id(), '_nettivarasto_supplier_code', true) ) $product_array['SupplierCode'] = get_post_meta($WC_product->get_id(), '_nettivarasto_supplier_code', true);
            if ( get_post_meta($WC_product->get_id(), '_nettivarasto_group', true) ) $product_array['Group'] = get_post_meta($WC_product->get_id(), '_nettivarasto_group', true);
            if ( get_post_meta($WC_product->get_id(), '_nettivarasto_purchase_price', true) ) $product_array['Price'] = get_post_meta($WC_product->get_id(), '_nettivarasto_purchase_price', true);
            //Add product array to array
            $NV_products['Products']['Product'][] = $product_array;
		    
            //Reset variables just in case
            $product_array = '';
            $PictureUrl = '';
            $variations = ''; 
			}
          }
        }
  
        else {
          $PictureUrl = wp_get_attachment_image_src( $WC_product->get_image_id(), 'shop_thumbnail' );
		  $productSku = $WC_product->get_sku();
		  
		  if(!empty($productSku)){
          $product_array = array(
            'Code' => $WC_product->get_sku(),
            'Name' => $WC_product->get_name(),
            'Description' => strip_tags($WC_product->get_description()),
            'InfoUrl' => get_permalink($WC_product->get_id()),
            'SalesPrice' => wc_get_price_including_tax($WC_product),
			'Weight'=> $WC_product->get_weight(),
			'Height'=> $WC_product->get_height(),
			'Width'=> $WC_product->get_width(),
			'Depth'=> $WC_product->get_length(),
			'VatPercentage'=> $_tax->get_rate_percent($WC_product->get_tax_class()),
            'Currency' => get_woocommerce_currency()
          );
          if ( get_post_meta($WC_product->get_id(), '_nettivarasto_supplier_name', true) ) $product_array['Supplier'] = get_post_meta($WC_product->get_id(), '_nettivarasto_supplier_name', true);
          if ( get_post_meta($WC_product->get_id(), '_nettivarasto_supplier_code', true) ) $product_array['SupplierCode'] = get_post_meta($WC_product->get_id(), '_nettivarasto_supplier_code', true);
          if ( get_post_meta($WC_product->get_id(), '_nettivarasto_group', true) ) $product_array['Group'] = get_post_meta($WC_product->get_id(), '_nettivarasto_group', true);
          if ( get_post_meta($WC_product->get_id(), '_nettivarasto_purchase_price', true) ) $product_array['Price'] = get_post_meta($WC_product->get_id(), '_nettivarasto_purchase_price', true);
          if ( $PictureUrl ) $product_array['PictureUrl'] = $PictureUrl;
          $NV_products['Products']['Product'][] = $product_array;
        }
        }
        //Reset variables just in case
        $product_array = '';
        $PictureUrl = '';
        $variations = '';
      } 
    }  

    if ( $sitepress ) {
      //Let's switch back to original language
      $sitepress->switch_lang( $original_language );
    }

	//print '<pre>';
	//print_r($NV_products);
	//exit;

    $response = $this->api->updateAllProducts($NV_products);
    if ( $response ) {
      if ( ! ( (string)$response['Response']['Info']['@Success'] == 'true' ) ) {
        $this->error = $response['Response']['Info']['@Error'];
      } else {
		$this->notice = __('Product export completed.', 'ogoship-nettivarasto-api-for-woocommerce');
      }
    }
  }
  
	function getMetaValue( $post_id ){
		global $wpdb;
		$meta_value = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM $wpdb->postmeta WHERE meta_key='_nettivarasto_no_export' AND post_id='%s' LIMIT 1", $post_id ) );
		if ( $meta_value ) return $meta_value;
		return null;
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
          <p><?php echo '<strong>OGOship:</strong> '.$this->notice; ?></p>
      </div>
      <?php
    }
    $this->notice = '';
    if($this->error) {
      ?>
      <div class="error">
          <p><?php echo '<strong>OGOship:</strong> '.$this->error; ?></p>
      </div>
      <?php
    }
    $this->error = '';
  }

  function add_order_meta_box_actions($order) {
    $actions['nv_send_order_to_nettivarasto'] = __( 'Send Order to OGOship', 'ogoship-nettivarasto-api-for-woocommerce' );
    return $actions;
  }
  
  function process_action_nv_send_order_to_nettivarasto( $order ) {
    $this->save_order_to_nettivarasto($order->get_id());
  }

  function get_latest_changes() {
    global $woocommerce;
    global $woocommerce_wpml;
  
    $latest = $this->api->latestChanges($latestProducts, $latestOrders);
      
    if($latestOrders) {
        foreach($latestOrders as $latestOrder) {
			if(!preg_match('/^(?<id>\d+)-?(?<key>[a-z0-9]+)?$/', $latestOrder->getReference(), $matches)){
				continue;
			}
			$order_id = 0;
			if(isset($matches['key'])){
				$order_id = wc_get_order_id_by_order_key('wc_order_' . $matches['key']);
			}
			elseif(isset($matches['id']) && $order_id == 0)
			{
				$order_id = $matches['id'];
			}
			if($order_id == 0){
				continue;
			}
            if( get_post_status ( $order_id ) ) {
              $WC_order = null;
              try {
                $WC_order = new WC_order( $order_id );
              } catch(Exception $e){
                $this->error .= __('Error loading order', 'ogoship-nettivarasto-api-for-woocommerce') . ":'" . $order_id . "' "
                  . __('from WooCommerce, skipping', 'ogoship-nettivarasto-api-for-woocommerce') . "<br/>";
              }
                if($WC_order == null){
                  continue;
                }
                switch ( $latestOrder->getStatus() ) {
                    case  'SHIPPED': 
						if(!$WC_order->has_status('completed'))
						{
							update_post_meta( $order_id, 'nettivarasto_tracking', $latestOrder->getTrackingNumber() );
							$WC_order->add_order_note(__('OGOship change of status to SHIPPED.', 'ogoship-nettivarasto-api-for-woocommerce'), 0);
							$WC_order->add_order_note(__('Tracking code', 'ogoship-nettivarasto-api-for-woocommerce').': '.$latestOrder->getTrackingNumber(), 0);
							$WC_order->update_status('completed');
						}
                        break;
                    case  'CANCELLED':
                        $WC_order->add_order_note(__('OGOship change of status to CANCELLED.', 'ogoship-nettivarasto-api-for-woocommerce'), 0);
                        break;
                    case  'COLLECTING':
                        $WC_order->add_order_note(__('OGOship change of status to COLLECTING.', 'ogoship-nettivarasto-api-for-woocommerce'), 0);
                        break;
                    case  'PENDING':
                        $WC_order->add_order_note(__('OGOship change of status to PENDING.', 'ogoship-nettivarasto-api-for-woocommerce'), 0);
                        break;
                    case  'RESERVED':
                        $WC_order->add_order_note(__('OGOship change of status to RESERVED.', 'ogoship-nettivarasto-api-for-woocommerce'), 0);
                        break;
                    case  'DRAFT':
                        $WC_order->add_order_note(__('OGOship change of status to DRAFT.', 'ogoship-nettivarasto-api-for-woocommerce'), 0);
                        break;
                }
            }
        }
    }

    if($latestProducts) {
      foreach($latestProducts as $latestProduct) {
          $WC_product = $this->get_product_by_sku( $latestProduct->getCode() );
              if($WC_product) {
                  wc_update_product_stock($WC_product, $latestProduct->getStock(), 'set' );
                  if ( $woocommerce_wpml && $woocommerce_wpml->products) {
                      if(method_exists($woocommerce_wpml->products, "sync_post_action")){
                          $woocommerce_wpml->products->sync_post_action( $WC_product->get_id(), $WC_product );
                      }
                  }
                  if ( $latestProduct->getStock() ) {
                  $WC_product->set_stock_status( 'instock' );
              }
           }
      }
    }
    update_option('nettivarasto_latest_changes_timestamp', $this->api->getTimestamp());
    $this->notice = __('Product and order data updated from OGOship.', 'ogoship-nettivarasto-api-for-woocommerce');
  }
  
  function get_product_by_sku( $sku ) {
      global $wpdb;
      $product_id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key='_sku' AND meta_value='%s' LIMIT 1", $sku ) );
      if ( $product_id ) return wc_get_product( $product_id );
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
      $tracking_code = get_post_meta( $order->get_id(), 'nettivarasto_tracking', true );
      if($tracking_code) {
        echo "\n".__('Tracking code', 'ogoship-nettivarasto-api-for-woocommerce').': '.$tracking_code."\n";
      }
    } else {
      //Do this if we have a normal email
      $tracking_code = get_post_meta( $order->get_id(), 'nettivarasto_tracking', true );
      if($tracking_code) {
        echo '<div>';
          echo '<h3>'.__('Track Your Shipment', 'ogoship-nettivarasto-api-for-woocommerce').'</h3>';
          echo '<p>'.__('Tracking code', 'ogoship-nettivarasto-api-for-woocommerce').': '.$tracking_code.'</p>';
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
        echo '<h3>'.__('Track Your Shipment', 'ogoship-nettivarasto-api-for-woocommerce').'</h3>';
        echo '<p>'.__('Tracking code', 'ogoship-nettivarasto-api-for-woocommerce').': '.$tracking_code.'</p>';
      echo '</div>';
    }
  }
}

$nv_wc_api = new nv_wc_api();