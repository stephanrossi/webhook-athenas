<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookAthena extends Model
{
    // Especifica o nome da tabela se não seguir o padrão plural
    protected $table = 'webhook_athenas';

    // Permite a atribuição em massa para todos os campos
    protected $guarded = [];
}
