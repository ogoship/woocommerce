<?php

require_once 'Order.php';
require_once 'Product.php';
require_once 'REST-client.php';

class NettivarastoAPI
{
  private $merchantID = '';
  private $secretToken = '';
  private $timestamp = 0;
  private $error = '';
  
  function __construct($merchantID, $secretToken)
  {
    $this->merchantID = $merchantID;
    $this->secretToken = $secretToken;
  }

  function setTimestamp($timestamp)
  {
    $this->timestamp = $timestamp;
  }

  function getTimestamp()
  {
    return $this->timestamp;
  }

  function getMerchantID()
  {
    return $this->merchantID;
  }
  
  function setError($error)
  {
    $this->error = $error;
  }
  
  function getLastError()
  {
    return $this->error;
  }

  function updateAllProducts($products) {
  	$strParameters = array("product","all");
    $restClient = new NettivarastoAPI_RESTclient($this, 'POST', '/Products', $strParameters);
    $restClient->setPostData($products);
    $resultArray = array();
    $success = $restClient->execute($resultArray);
    return $resultArray;
  }

  function getAllProducts() {
    $restClient = new NettivarastoAPI_RESTclient($this, 'GET', '/Products', '');
    $resultArray = array();
    $success = $restClient->execute($resultArray);
    return $resultArray;
  }
  
  function latestChanges(&$products, &$orders)
  {
    $restClient = new NettivarastoAPI_RESTclient($this, 'GET', '/LatestChanges', array('changes', $this->timestamp));
    $restClient->addGetParameter('TimeStamp', $this->timestamp);
    $resultArray = array();
    $success = $restClient->execute($resultArray);
    
    if ($success)
    {
      if (array_key_exists('@Timestamp', $resultArray['Response']['Info']))
      {
        $this->timestamp = $resultArray['Response']['Info']['@Timestamp'];
      }
      else
      {
        $success = false;
      }
      if($resultArray['Response']['Products']) {
        foreach ($resultArray['Response']['Products'] as $type => $data)
        {
          if ($type == 'Product')
          {
            if (array_key_exists('0', $data))
            {
              // List of products
              foreach ($data as $productData)
              {
                $product = NettivarastoAPI_Product::createFromArray($this, $productData);
                if ($product !== null)
                {
                  $products[] = $product;
                }
              }
            }
            else
            {
              // Single product
              $product = NettivarastoAPI_Product::createFromArray($this, $data);
              if ($product !== null)
              {
                $products[] = $product;
              }
            }
          }
         }
      }
      if($resultArray['Response']['Orders']) {
        foreach ($resultArray['Response']['Orders'] as $type => $data)
        {
          if ($type == 'Order')
          {
            if (array_key_exists('0', $data))
            {
              // List of orders
              foreach ($data as $orderData)
              {
                $order = NettivarastoAPI_Order::createFromArray($this, $orderData);
                if ($order !== null)
                {
                  $orders[] = $order;
                }
              }
            }
            else
            {
              // Single order
              $order = NettivarastoAPI_Order::createFromArray($this, $data);
              if ($order !== null)
              {
                $orders[] = $order;
              }
            }
          }
        }
      }
    }
    
    /// \todo REMOVE
    ///echo '<div style="display: none">$resultArray = ';
    ///print_r($resultArray);
    ///echo "</div>\n";
    
    return $success;
  }
  
  function getOrder($orderID, &$order)
  {
    $restClient = new NettivarastoAPI_RESTclient($this, 'GET', '/Order/' . urlencode($orderID), array('order', 'info', $orderID));
    $resultArray = array();
    $success = $restClient->execute($resultArray);
    
    if ($success)
    {
      foreach ($resultArray['Response'] as $type => $data)
      {
        if ($type == 'Order')
        {
          $order = NettivarastoAPI_Order::createFromArray($this, $data);
          break;
        }
      }
    }
    
 

    
    return $success;
  }
  
  function getSHA1($parameters)
  {
    /// \todo REMOVE
    //return 'Dem0';
	//$parameters = array("product","all");
    return sha1(implode(',', $parameters) . ',' . $this->secretToken);
  }
  function getProduct($productCode, &$product)
  {
    $restClient = new NettivarastoAPI_RESTclient($this, 'GET', '/Product/' . urlencode($productCode), array('product', 'info', $productCode));
    $resultArray = array();
    $success = $restClient->execute($resultArray);
    
    if ($success)
    {
      foreach ($resultArray['Response'] as $type => $data)
      {
        if ($type == 'Product')
        {
          $product = NettivarastoAPI_Product::createFromArray($this, $data);
          break;
        }
      }
    }
    
    /// \todo REMOVE
    ///echo '<div style="display: none">$resultArray = ';
    //print_r($resultArray);
    ///print_r($product);
    ///echo "</div>\n";
    
    return $success;
  }
}

?>
