<?php

class NettivarastoAPI_Object
{
  protected $attributes = array();
  protected $attributesModified = array();
  
  // Something(2)::SomethingElse::SomethingElseAlso[5]::AttributeName   =>  array SomethingElseAlso[5]
  private function findAttributesArray(&$attribute, &$attributesArray)
  {
    $parts = explode('::', $attribute);
    if (count($parts) == 1)
    {
      // Last one: 'AttributeName'
      return array_key_exists($attribute, $attributesArray);
    }
    else if (strpos($parts[0], '[') === false)
    {
      // 'AttributeName::'
      if (!array_key_exists($parts[0], $attributesArray))
      {
        return false;
      }
      $attributesArray = $attributesArray[$parts[0]];
      unset($parts[0]);
      $attribute = implode('::', $parts);
      return $this->findAttributesArray($attribute, $attributesArray);
    }
    else
    {
      // 'AttributeName[index]::'
      $parts2 = explode('[', $parts[0]);
      $parts3 = explode(']', $parts2[1]);
      $key = $parts2[0];
      $index = $parts3[0];
      if (!array_key_exists($key, $attributesArray) || !array_key_exists($index, $attributesArray[$key]))
      {
        return false;
      }
      $attributesArray = $attributesArray[$key][$index];
      unset($parts[0]);
      $attribute = implode('::', $parts);
      return $this->findAttributesArray($attribute, $attributesArray);
    }
  }
  
  protected function getAttribute($name, $defaultValue = '')
  {
    $attributesArray = $this->attributes;
    if ($this->findAttributesArray($name, $attributesArray))
    {
      return $attributesArray[$name];
    }
    else
    {
      return $defaultValue;
    }
  }
  
  protected function getAttributeCount($name)
  {
    $attributesArray = $this->attributes;
    if ($this->findAttributesArray($name, $attributesArray) && is_array($attributesArray[$name]))
    {
      return count($attributesArray[$name]);
    }
    else
    {
      return -1;
    }
  }
  
  // Something[2]::SomethingElse::SomethingElseAlso[5]::AttributeName   =  $value
  protected function setAttribute($name, $value)
  {
    if (is_array($value))
    {
      // Convert array-value to multiple setAttribute()-calls.
      foreach ($value as $key => $valueValue)
      {
        $this->setAttribute("$name::$key", $valueValue);
      }
      return;
    }
    
    $parts = explode('::', $name);
    
    $attributesArray = &$this->attributes;
    $attributesModifiedArray = &$this->attributesModified;

    for ($i = 0; $i < count($parts); ++$i)
    {
      $part = $parts[$i];
      
      if ($i == count($parts) - 1)
      {
        // Last one: 'AttributeName'
        $attributesArray[$part] = $value;
        $attributesModifiedArray[$part] = true;
      }
      else if (strpos($part, '[') === false)
      {
        // 'AttributeName::'
        if (!array_key_exists($part, $attributesArray))
        {
          $attributesArray[$part] = array();
          $attributesModifiedArray[$part] = array();
        }
        $attributesArray = &$attributesArray[$part];
        $attributesModifiedArray = &$attributesModifiedArray[$part];
      }
      else
      {
        // 'AttributeName[index]::'
        $parts2 = explode('[', $part);
        $parts3 = explode(']', $parts2[1]);
        $key = $parts2[0];
        $index = $parts3[0];
        
        if (!array_key_exists($key, $attributesArray))
        {
          $attributesArray[$key] = array();
          $attributesModifiedArray[$key] = array();
        }
        
        if (!array_key_exists($index, $attributesArray[$key]))
        {
          $attributesArray[$key][$index] = array();
          $attributesModifiedArray[$key][$index] = array();
        }
        
        $attributesArray = &$attributesArray[$key][$index];
        $attributesModifiedArray = &$attributesModifiedArray[$key][$index];
      }
    }
  }
  
  protected function getArrayOfModifiedAttributes()
  {
    $result = array();
    $this->getArrayOfModifiedAttributesRecursive($this->attributes, $result, $this->attributesModified);
    return $result;
  }
  
  private function getArrayOfModifiedAttributesRecursive(&$from, &$to, &$modified)
  {
    foreach (array_keys($modified) as $key)
    {
      if (is_array($from[$key]))
      {
        $to[$key] = array();
        $this->getArrayOfModifiedAttributesRecursive($from[$key], $to[$key], $modified[$key]);
      }
      else
      {
        $to[$key] = $from[$key];
      }
    }
  }
}

?>
