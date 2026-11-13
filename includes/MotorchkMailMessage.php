<?php

use PHPMailer\PHPMailer\PHPMailer;

/**
 * Письмо ядра всегда собирается как multipart/alternative.
 * У PHPMailer alternativeExists() — это empty($AltBody), поэтому пустая строка и «0»
 * не попадали бы в текстовую часть.
 */
class MotorchkMailMessage extends PHPMailer
{
    public function alternativeExists(): bool
    {
        return true;
    }
}
