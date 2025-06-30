<?php

    class ApiException extends Exception
    {
        protected $errors = array();

        public function __construct($message, $code = 0, Throwable $previous = null) {
            parent::__construct($message, $code, $previous);
        }

        public function setErrors($errors)
        {
            $this->errors = $errors;
        }

        public function getErrors()
        {
            return $this->errors;
        }
    }