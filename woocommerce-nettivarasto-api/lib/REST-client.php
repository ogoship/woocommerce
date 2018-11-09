<?php

class NettivarastoAPI_RESTclient
{
  private $api = null;
  private $method = '';
  private $url = '';
  private $shaParameters = '';
  private $getParameters = array();
  private $dataArray = array();
  private $json = true;
  private $restClientVersion = "OGOshipPHP/1.3";
  private $pluginVersion = '?';
  
  function __construct(NettivarastoAPI $api, $method, $url, $shaParameters)
  {
    $this->api = $api;
    $this->method = $method;
    $this->url = $url;
    $this->shaParameters = $shaParameters;
  }
  function setVersion($version)
  {
      $this->pluginVersion = $version;
  }
  
  function addGetParameter($key, $value)
  {
    $this->getParameters[$key] = $value;
  }
  
  function setPostData($dataArray)
  {
    if (is_array($dataArray))
    {
      $this->dataArray = $dataArray;
    }
  }
  
  function useJson()
  {
    $this->json = true;
  }
  
  function useXML()
  {
    $this->json = false;
  }

  function execute(&$resultArray)
  {
    // Clear old error messages.
    $this->api->setError('');
    
    // Remove '/' from the end of url.
    if ($this->url[strlen($this->url) - 1] == '/')
    {
      $this->url = substr($this->url, 0, -1);
    }

    // Create url.
    $this->url = '/merchant/' . urlencode($this->api->getMerchantID()) . $this->url . '?SHA1=' . $this->api->getSHA1($this->shaParameters);

    // Append other parameters.
    foreach ($this->getParameters as $key => $value)
    {
      $this->url .= '&' . urlencode($key) . '=' . urlencode($value);
    }

    // Create HTTPS-request.
    $context = stream_context_create(array(
        'http' => array(
            'header' => "Content-type: application/json\r\nConnection: close\r\n"
            . "User-Agent: " . $this->restClientVersion . " (" . $this->pluginVersion . ")\r\n" ,
            'method' => $this->method,
            'content' => json_encode($this->dataArray)
        )
    ));
    // do the request
    $result = file_get_contents("http://my.ogoship.com" . $this->url, false, $context);

    // Response data type.
    if ($this->json)
    {
      // JSON response.
      $jsonData = @json_decode($result, true);
      $resultArray = $jsonData;
      if ($jsonData === null)
      {
        return false;
      }

      if (is_array($jsonData) &&
          array_key_exists('Response', $jsonData) &&
          is_array($jsonData['Response']) && 
          array_key_exists('Info', $jsonData['Response']) &&
          is_array($jsonData['Response']['Info']) &&
          array_key_exists('@Success', $jsonData['Response']['Info']))
      {
        if ($jsonData['Response']['Info']['@Success'] == 'true')
        {
          return true;
        }
        else
        {
          if (array_key_exists('@Error', $jsonData['Response']['Info']))
          {
            $this->api->setError($jsonData['Response']['Info']['@Error']);
          }

          return false;
        }
      }
      else
      {
        return false;
      }
    }
    else
    {
      // XML response.
      
      /// \todo XML
      return false;
    }
  }
}

?>
