<?php

namespace pz\Routing;

trait DataHandler {
    protected array $data_keys_excluded = [
        'nonce',
        'nonce_expiration',
    ];

    protected function getData(): array
    {
        # Return the data array, excluding any keys that are not meant to be exposed
        return array_diff_key($this->data ?? [], array_flip($this->data_keys_excluded));
    }

    /**
     * Retrieves the data associated with the current request.
     *
     * @param string|null $key The key to retrieve from the data array. If null, returns the entire data array.
     * @param string|null $default The default value to return if the key does not exist in the data array.
     * @return mixed The data of the request.
     */
    public function data(
        ?string $key = null,
        ?string $default = null
    ): mixed
    {
        if ($this->data === null) {
            return [];
        }

        if ($key !== null) {
            return $this->data[$key] ?? $default;
        }

        return $this->getData();
    }

    /**
     * Sets the data for the request, optionally extracting and storing a nonce value.
     *
     * This method updates the internal data array by merging the provided data
     * with the existing data. If the provided data contains a 'nonce' key, its
     * value is stored separately in the `$nonce` property, and the key is removed
     * from the data array before merging.
     *
     * @param array $data An associative array of data to set. If it contains a 'nonce'
     *                    key, the value will be extracted and stored in the `$nonce`
     *                    property, and the key will be removed from the array.
     */
    public function setData(array $data)
    {
        # Remove keys that are not meant to be exposed
        foreach ($this->data_keys_excluded as $key) {
            if (isset($data[$key])) {
                unset($data[$key]);
            }
        }

        # Merge the new data with the existing data
        $this->data = $data;
    }

    /**
     * Adds a key-value pair to the request data.
     *
     * @param string $key The key to associate with the value.
     * @param mixed $value The value to store.
     * @return static Returns the current instance for method chaining.
     */
    public function addData(
        string $key, 
        mixed $value
    ): static
    {
        if(!in_array($key, $this->data_keys_excluded)) {
            $this->data[$key] = $value;
        }
        return $this;
    }

    /**
     * Checks if the specified key exists in the data array and is not an empty string.
     *
     * @param string $key The key to check in the data array.
     * @return bool Returns true if the key exists and its value is not an empty string, otherwise false.
     */
    public function hasData(String $key): bool
    {
        if (!isset($this->data[$key])) {
            return false;
        }
        return $this->data[$key] != '';
    }

    /**
     * Checks if a specific key exists in the data. If the key does not exist, 
     * it sets the key with the provided value. Returns the current instance 
     * for method chaining.
     *
     * @param string $key The key to check or set in the data.
     * @param mixed $value The value to set if the key does not exist.
     * @return static Returns the current instance for method chaining.
     */
    public function hasOrSetData(String $key, $value): static
    {
        if (!$this->hasData($key)) {
            $this->addData($key, $value);
        }
        return $this;
    }

    /**
     * Merge the current data with new data.
     * 
     * @param array $data The data to merge
     * @param bool $kee_current_data If set to true, the current data takes precent. By default (false), the new data overrides the current data
     * @return self Returns the current instance for method chaining.
     */
    public function mergeData(array $data, bool $keep_current_data = false): self {
        if($keep_current_data) {
            $this->data = array_merge($data, $this->data ?? []);
        } else {
            $this->data = array_merge($this->data ?? [], $data);
        }

        return $this;
    }
}