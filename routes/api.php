<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\RagController;
use Cloudstudio\Ollama\Services\ModelService;
use Illuminate\Support\Facades\Log;

Route::get('/rag/setup', [RAGController::class, 'setupQdrant']);
Route::post('/rag/upload', [RagController::class, 'upload']);
Route::post('/rag/ask', [RagController::class, 'ask']);