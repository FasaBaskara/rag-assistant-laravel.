<?php

use Illuminate\Support\Facades\Route;
use Cloudstudio\Ollama\Facades\Ollama;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;
use Illiminate\console\BufferedConsoleOutput;
use App\Http\Controllers\ChatbotController;
use App\Http\Controllers\RAGController;


Route::get('/', [RAGController::class, 'showChat'])->name('chat');
Route::get('/chat', [RAGController::class, 'showChat']);
Route::post('/rag/ask', [RAGController::class, 'ask']);
Route::post('/rag/ask', [RAGController::class, 'ask'])->name('chat.ask');