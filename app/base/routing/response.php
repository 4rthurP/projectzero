<?php

namespace pz\Routing;

use DateTime;
use DateTimeZone;

use pz\Config;
use pz\Enums\Routing\ResponseCode;

class Response
{
    public bool $success;
    protected ResponseCode $code;
    protected int $http_code;
    public ?string $answer;
    public ?string $message;
    protected ?array $data;
    protected ?array $data_messages;
    protected ?string $header = null;
    protected ?string $redirect;
    protected ?string $nonce = null;
    protected ?string $nonce_expiration = null;

    public function __construct(
        bool $success, 
        ResponseCode $code, 
        ?string $answer = null, 
        ?string $redirect = null, 
        ?array $data = null, 
        ?array $data_messages = null
    ) {
        $this->success = $success;
        $this->code = $code;
        $this->http_code = $code->value;
        $this->data = $data;
        $this->data_messages = $data_messages;
        $this->answer = $answer;
        $this->redirect = $redirect;
        $this->message = null;
    }


    #####################################
    # User and Authentication
    #####################################

    /**
     * Sets the nonce and its expiration date.
     *
     * @param string|null $nonce The nonce value, or null if not set.
     * @param DateTime $nonce_expiration The expiration date and time for the nonce.
     * @return self Returns the current instance for method chaining.
     */
    public function setNonce(?string $nonce, DateTime $nonce_expiration): self
    {
        $this->nonce = $nonce ?? null;
        $this->nonce_expiration = $nonce_expiration->format('Y-m-d H:i:s');
        return $this;
    }

    /**
     * Retrieves the nonce value.
     *
     * @return ?string The nonce value.
     */
    public function getNonce(): ?string
    {
        return $this->nonce;
    }

    /**
     * Retrieves the expiration date and time of the nonce.
     *
     * @return DateTime The DateTime object representing the nonce expiration.
     * @throws Exception If the DateTime or DateTimeZone instantiation fails.
     */
    public function getNonceExpiration(): DateTime
    {
        return new DateTime($this->nonce_expiration, Config::tz());
    }


    #####################################
    # Response Data
    #####################################

    /**
     * Retrieves the response's answer.
     *
     * @return ?string The answer.
     */
    public function getAnswer(): ?string
    {
        return $this->answer;
    }

    /**
     * Sets the answer for the response.
     *
     * @param string $answer The answer to be set.
     * @return self Returns the current instance for method chaining.
     */
    public function setAnswer(string $answer): self
    {
        $this->answer = $answer;
        return $this;
    }

    /**
     * Retrieves data from the response.
     *
     * If a key is provided, it attempts to return the value associated with that key.
     * If no key is provided, it returns the entire data array.
     * If the data is null, it returns null.
     *
     * @param string|null $key The key to retrieve from the data array (optional).
     * @return mixed|null The value associated with the key, the entire data array, or null if no data exists.
     */
    public function data(?string $key = null)
    {
        if ($this->data === null) {
            return [];
        }

        if ($key !== null) {
            return $this->data[$key] ?? null;
        }

        return $this->data;
    }

    /**
     * Retrieves data messages or a specific message by key.
     *
     * @param string|null $key The key of the specific message to retrieve. If null, all data messages are returned.
     * @return mixed|null Returns the specific message if a key is provided and exists, 
     *                    all data messages if no key is provided, or null if no messages are set.
     */
    public function dataMessages(?string $key = null)
    {
        if ($this->data_messages === null) {
            return null;
        }

        if ($key !== null) {
            return $this->data_messages[$key] ?? null;
        }

        return $this->data_messages;
    }

    /**
     * Adds data to the response under the specified element key.
     *
     * @param string $element The key under which the data will be stored.
     * @param array $data The data to be added.
     * @return self Returns the current instance for method chaining.
     */
    public function addData(string $element, array $data): self
    {
        $this->data[$element] = $data;
        return $this;
    }

    /**
     * Sets the data for the response.
     *
     * @param array $form_data The data to be set.
     * @return self Returns the current instance for method chaining.
     */
    public function setData(array $form_data): self
    {
        $this->data = $form_data;
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

    /**
     * Sets the data messages for the response.
     *
     * @param array $data_messages An array of data messages to set.
     * @return self Returns the current instance for method chaining.
     */
    public function setDataMessages(array $data_messages): self
    {
        $this->data_messages = $data_messages;
        return $this;
    }

    #####################################
    # General getters and setters
    #####################################

    /**
     * Determines if the response is successful.
     *
     * @return bool Returns true if the response is successful, false otherwise.
     */
    public function isSuccessful(): bool
    {
        return $this->success;
    }

    /**
     * Retrieves the response code.
     *
     * @return ResponseCode The response code associated with the current instance.
     */
    public function getResponseCode(): ResponseCode
    {
        return $this->code;
    }

    /**
     * Checks if a redirect has been set for the response.
     *
     * @return bool Returns true if a redirect is set, false otherwise.
     */
    public function hasRedirect(): bool
    {
        return $this->redirect !== null;
    }

    /**
     * Sets the redirect URL.
     *
     * @param string|null $redirect The URL to redirect to, or null to clear the redirect.
     * @return void
     */
    public function setRedirect(?string $redirect): void
    {
        $this->redirect = $redirect;
    }

    /**
     * Generates a redirect URL with additional query parameters based on the success state.
     *
     * @return string The redirect URL prefixed with "Location: ".
     *
     * The method appends query parameters to the redirect URL:
     * - If the operation was not successful, it adds an "error" parameter with the error code.
     * - If the operation was successful, it adds a "success" parameter with the value "true".
     * - If a message is provided, it adds a "message" parameter with the message content.
     */
    public function getRedirect(): string
    {
        $request = $this->redirect ?? '';

        if (!$this->success) {
            $request = $this->addParamToRequest($request, 'error', $this->code->toParam());
        } else {
            $request = $this->addParamToRequest($request, 'success', 'true');
        }


        if ($this->message != '') {
            $request = $this->addParamToRequest($request, 'message', $this->message);
        }

        return 'Location: ' . $request;
    }
    
    
    #####################################
    # Helpers and utilities
    #####################################
    
    /**
     * Converts the response object to an associative array.
     *
     * @return array An associative array containing the following keys:
     *               - 'success' (bool): Indicates whether the operation was successful.
     *               - 'code' (mixed): The response code value.
     *               - 'answer' (mixed): The answer associated with the response.
     *               - 'message' (string|null): A message providing additional information.
     *               - 'data' (mixed): The main data payload of the response.
     *               - 'data_messages' (mixed): Additional data messages, if any.
     *               - 'header' (mixed): The header information for the response.
     *               - 'redirect' (string|null): A URL to redirect to, if applicable.
     *               - 'nonce' (string|null): A unique nonce value for security purposes.
     *               - 'nonce_expiration' (int|null): The expiration timestamp of the nonce.
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'code' => $this->code->value,
            'answer' => $this->answer,
            'message' => $this->message,
            'data' => $this->data,
            'data_messages' => $this->data_messages,
            'header' => $this->header,
            'redirect' => $this->redirect,
            'nonce' => $this->nonce,
            'nonce_expiration' => $this->nonce_expiration
        ];
    }

    /**
     * Adds a query parameter to the given request URL.
     *
     * If the request URL does not already contain a query string, the parameter
     * is added as the first query parameter. Otherwise, it is appended to the
     * existing query string.
     *
     * @param string $request The original request URL.
     * @param string $param The name of the query parameter to add.
     * @param string $value The value of the query parameter to add.
     * @return string The modified request URL with the new query parameter.
     */
    private function addParamToRequest(string $request, string $param, string $value): string
    {
        if (strpos($request, '?') === false) {
            return $request . '?' . $param . '=' . $value;
        }
        return $request . '&' . $param . '=' . $value;
    }
}
