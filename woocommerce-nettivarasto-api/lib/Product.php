<?php

require_once 'Object.php';
require_once 'REST-client.php';

class NettivarastoAPI_Product extends NettivarastoAPI_Object
{
  protected $api = null;
  protected $createNew = true;
  protected $productCode = 0;
  
  function __construct(NettivarastoAPI $api, $productCode)
  {
    $this->api = $api;
    $this->productCode = $productCode;
  }
  
  function save()
  {
    $method = 'POST';
    $shaMethod = 'update';
    if ($this->createNew)
    {
      $method = 'PUT';
      $shaMethod = 'add';
    }
    $restClient = new NettivarastoAPI_RESTclient($this->api, $method, '/Product/' . urlencode($this->productCode),
                                                 array('product', $shaMethod, $this->productCode));
    $restClient->setPostData(array('Product' => $this->getArrayOfModifiedAttributes()));
    
    $resultArray = array();
    $success = $restClient->execute($resultArray);
    
    /// \todo REMOVE
    //echo '<div style="display: none">$resultArray = ';
    //print_r($resultArray);
    //echo "</div>\n";
    
    if ($success)
    {
      $this->attributesModified = array();
      
      if ($this->createNew)
      {
        $this->createNew = false;
      }
    }
    
    return $success;
  }
  
  function delete()
  {
    $restClient = new NettivarastoAPI_RESTclient($this->api, 'DELETE', '/Product/' . urlencode($this->productCode),
                                                 array('product', 'remove', $this->productCode));
    $resultArray = array();
    return $restClient->execute($resultArray);
  }
  
  static function createFromArray(NettivarastoAPI $api, $data)
  {
    if (array_key_exists('Code', $data))
    {
      $product = new NettivarastoAPI_Product($api, $data['Code']);
      $product->createNew = false;
 
      foreach ($data as $key => $value)
      {
        if ($key != 'Code')
        {
          $product->attributes[$key] = $value;
        }
      }
      
      return $product;
    }
    else
    {
      return null;
    }
  }
  
  //
  // Getter and setter methods
  //
  
  /**
   * Get display name of product.
   */
  function getName()
  {
    return $this->getAttribute('Name');
  }

  /**
   * Set display name of product.
   */
  function setName($value)
  {
    $this->setAttribute('Name', $value);
  }
  
  /**
   * Get additional information about product.
   */
  function getDescription()
  {
    return $this->getAttribute('Description');
  }

  /**
   * Set additional information about product.
   */
  function setDescription($value)
  {
    $this->setAttribute('Description', $value);
  }
  
  /**
   * Get unique product code.
   */
  function getCode()
  {
    return $this->productCode;
  }
  
  /**
   * Get manufacturer given code of this product.
   */
  function getManufacturerCode()
  {
    return $this->getAttribute('ManufacturerCode');
  }

  /**
   * Set manufacturer given code of this product.
   */
  function setManufacturerCode($value)
  {
    $this->setAttribute('ManufacturerCode', $value);
  }
  
  /**
   * Get EAN code of product.
   */
  function getEANCode()
  {
    return $this->getAttribute('EANCode');
  }

  /**
   * Set EAN code of product.
   */
  function setEANCode($value)
  {
    $this->setAttribute('EANCode', $value);
  }
  
  /// \todo Setter-functions for these?
  
  /**
   * Get width of product.
   */
  function getWidth()
  {
    return $this->getAttribute('Width', -1);
  }

  /**
   * Get height of product.
   */
  function getHeight()
  {
    return $this->getAttribute('Height', -1);
  }
  
  /**
   * Get depth of product.
   */
  function getDepth()
  {
    return $this->getAttribute('Depth', -1);
  }

  /**
   * Get weight of product.
   */
  function getWeight()
  {
    return $this->getAttribute('Weight', -1);
  }

  /**
   * Get alarm level. Merchant can receive reports if stock is below this alarm level.
   */
  function getAlarmLevel()
  {
    return $this->getAttribute('AlarmLevel', -1);
  }
  
  /**
   * Set alarm level. Merchant can receive reports if stock is below this alarm level.
   */
  function setAlarmLevel($value)
  {
    $this->setAttribute('AlarmLevel', $value);
  }

  /**
   * Get count of products available in stock.
   */
  function getStock()
  {
    return $this->getAttribute('StockAvailable', 0);
  }
  
  function setStock($value)
  {
    $this->setAttribute('StockAvailable', $value);
  }
  
  /**
   * Get count of products available in stock.
   */
  function getVat()
  {
    return $this->getAttribute('VatPercentage', 0);
  }
  
  function setVat($value)
  {
    $this->setAttribute('VatPercentage', $value);
  }
  
}

?>
