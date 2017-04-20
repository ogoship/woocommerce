<?php

require_once 'Object.php';
require_once 'REST-client.php';

class NettivarastoAPI_Order extends NettivarastoAPI_Object
{
  protected $api = null;
  protected $createNew = true;
  protected $orderID = 0;
  
  function __construct(NettivarastoAPI $api, $orderID)
  {
    $this->api = $api;
    $this->orderID = $orderID;
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
    $restClient = new NettivarastoAPI_RESTclient($this->api, $method, '/Order/' . urlencode($this->orderID),
                                                 array('order', $shaMethod, $this->orderID));
    $restClient->setPostData(array('Order' => $this->getArrayOfModifiedAttributes()));

    $resultArray = array();
    $success = $restClient->execute($resultArray);
    
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
    $restClient = new NettivarastoAPI_RESTclient($this->api, 'DELETE', '/Order/' . urlencode($this->orderID),
                                                 array('order', 'remove', $this->orderID));
    $resultArray = array();
    return $restClient->execute($resultArray);
  }
  
  static function createFromArray(NettivarastoAPI $api, $data)
  {
    if (array_key_exists('Reference', $data))
    {
      $order = new NettivarastoAPI_Order($api, $data['Reference']);
      $order->createNew = false;
 
      foreach ($data as $key => $value)
      {
        if ($key != 'Reference')
        {
          $order->attributes[$key] = $value;
        }
      }
      
      return $order;
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
   * Get shipping method which you have enabled at Edit Merchant page.
   */
  function getShipping()
  {
    return $this->getAttribute('Shipping');
  }
  
  /**
   * Set shipping method which you have enabled at Edit Merchant page.
   */
  function setShipping($value)
  {
    $this->setAttribute('Shipping', $value);
  }
  
  /**
   * Get reference number of order.
   */
  function getReference()
  {
    return $this->orderID;
  }
  
  /**
   * Get status of order.
   *
   * List of possible values:
   * - DRAFT Can be used when manually creating order. Order is editable in Web. Warehouse will not do anything for orders in this state.
   * - NEW Order is waiting to be collected and shipped.
   * - COLLECTING Warehouse is collecting products. (It is not possible to cancel order anymore using Api.)
   * - PENDING Order is pending for some products.
   * - CANCELLED Order is cancelled.
   * - SHIPPED Order is shipped.
   * 
   * @return string DRAFT, NEW, COLLECTING, PENDING, CANCELLED, or SHIPPED.
   */
  function getStatus()
  {
    return $this->getAttribute('Status');
  }
  
  /**
   * Get latest edit time. (UTC)
   */
  function getEditTime()
  {
    return $this->getAttribute('EditTime');
  }

  /**
   * Get price which needed for "Postiennakko" and "Matkaennakko".
   */
  function getPriceTotal()
  {
    return $this->getAttribute('PriceTotal');
  }
  
  /**
   * Set price which needed for "Postiennakko" and "Matkaennakko".
   */
  function setPriceTotal($value)
  {
    $this->setAttribute('PriceTotal', $value);
  }
  
  /**
   * Warehouse will assign tracking number when available.
   */
  function getTrackingNumber()
  {
    return $this->getAttribute('TrackingNumber');
  }
  
  /**
   * Get any additional comments about order.
   */
  function getComments()
  {
    return $this->getAttribute('Comments');
  }
  
  /**
   * Write any additional comments about order.
   */
  function setComments($value)
  {
    $this->setAttribute('Comments', $value);
  }
  
  /**
   * Set to true for testing purposes.
   * 
   * @param bool $value True / false.
   */
  function setTestOnly($value)
  {
    $this->setAttribute('TestOnly', $value ? 'true' : 'false');
  }
  
  /**
   * Get name of customer.
   */
  function getCustomerName()
  {
    return $this->getAttribute("Customer::Name");
  }

  /**
   * Set name of customer.
   */
  function setCustomerName($value)
  {
    $this->setAttribute("Customer::Name", $value);
  }
  
  /**
   * Get first address row of customer.
   */
  function getCustomerAddress1()
  {
    return $this->getAttribute("Customer::Address1");
  }

  /**
   * Set first address row of customer.
   */
  function setCustomerAddress1($value)
  {
    $this->setAttribute("Customer::Address1", $value);
  }
  
  /**
   * Get second address row of customer.
   */
  function getCustomerAddress2()
  {
    return $this->getAttribute("Customer::Address2");
  }

  /**
   * Set second address row of customer.
   */
  function setCustomerAddress2($value)
  {
    $this->setAttribute("Customer::Address2", $value);
  }
  
  /**
   * Get city of customer.
   */
  function getCustomerCity()
  {
    return $this->getAttribute("Customer::City");
  }

  /**
   * Set city of customer.
   */
  function setCustomerCity($value)
  {
    $this->setAttribute("Customer::City", $value);
  }
  
  /**
   * Get country of customer.
   */
  function getCustomerCountry()
  {
    return $this->getAttribute("Customer::Country");
  }

  /**
   * Set country of customer.
   */
  function setCustomerCountry($value)
  {
    $this->setAttribute("Customer::Country", $value);
  }
  
  /**
   * Get zip of customer.
   */
  function getCustomerZip()
  {
    return $this->getAttribute("Customer::Zip");
  }

  /**
   * Set zip of customer.
   */
  function setCustomerZip($value)
  {
    $this->setAttribute("Customer::Zip", $value);
  }
  
  /**
   * Get phone number of customer.
   */
  function getCustomerPhone()
  {
    return $this->getAttribute("Customer::Phone");
  }

  /**
   * Set phone number of customer.
   */
  function setCustomerPhone($value)
  {
    $this->setAttribute("Customer::Phone", $value);
  }
  
  /**
   * Get e-mail address of customer.
   */
  function getCustomerEmail()
  {
    return $this->getAttribute("Customer::Email");
  }

  /**
   * Set e-mail address of customer.
   */
  function setCustomerEmail($value)
  {
    $this->setAttribute("Customer::Email", $value);
  }
  
  /**
   * Get count of order lines.
   */
  function getOrderLineCount()
  {
    return $this->getAttributeCount('OrderLines::OrderLine');
  }
  
  /**
   * Get code of product on order line.
   */
  function getOrderLineCode($index)
  {
    return $this->getAttribute("OrderLines::OrderLine[$index]::Code");
  }

  /**
   * Set code of product on order line.
   */
  function setOrderLineCode($index, $value)
  {
    $this->setAttribute("OrderLine[$index]::Code", $value);

    /// \todo If given when updating order then all order lines will be replaced with the ones sent with update.
  }
  
  /**
   * Get quantity of products on order line.
   */
  function getOrderLineQuantity($index)
  {
    return $this->getAttribute("OrderLines::OrderLine[$index]::Quantity");
  }

  /**
   * Set quantity of products on order line.
   */
  function setOrderLineQuantity($index, $value)
  {
    $this->setAttribute("OrderLine[$index]::Quantity", $value);
  }
  
  /**
   * Get documents.
   * 
   * @return array Key-value pairs of document names and urls.
   */
  function getDocuments()
  {
    return $this->getAttribute('Documents');
  }
  
  /**
   * Set documents.
   * 
   * @param array $value Key-value pairs of document names and urls.
   */
  function setDocuments($value)
  {
    $this->setAttribute('Documents', $value);
    
    /// \todo If given when updating order then all documents will be replaced with the ones sent with update.
  }
}

?>
