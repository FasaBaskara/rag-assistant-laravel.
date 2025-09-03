<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>RAG Project</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: #f4f7f6;
            margin: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }

        #chat-container {
            width: 90%;
            max-width: 700px;
            height: 90vh;
            background-color: #fff;
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
            display: flex;
            flex-direction: column;
        }

        #messages {
            flex-grow: 1;
            padding: 20px;
            overflow-y: auto;
            border-bottom: 1px solid #eee;
        }

        .message {
            margin-bottom: 15px;
            padding: 10px 15px;
            border-radius: 18px;
            max-width: 80%;
            line-height: 1.5;
        }

        .user {
            background-color: #007bff;
            color: white;
            align-self: flex-end;
            margin-left: auto;
        }

        .bot {
            background-color: #e9e9eb;
            color: #333;
            align-self: flex-start;
            margin-right: auto;
            white-space: pre-wrap;
        }

        #input-area {
            display: flex;
            padding: 15px;
            border-top: 1px solid #eee;
        }

        #user-input {
            flex-grow: 1;
            border: 1px solid #ccc;
            border-radius: 20px;
            padding: 10px 15px;
            font-size: 16px;
            outline: none;
        }

        #send-button {
            border: none;
            background-color: #007bff;
            color: white;
            border-radius: 50%;
            width: 44px;
            height: 44px;
            margin-left: 10px;
            cursor: pointer;
            font-size: 20px;
        }

        .spinner {
            border: 4px solid rgba(0, 0, 0, 0.1);
            width: 24px;
            height: 24px;
            border-radius: 50%;
            border-left-color: #333;
            animation: spin 1s ease infinite;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }
            100% {
                transform: rotate(360deg);
            }
        }
    </style>

</head>
<body>
    <div id="chat-container">
        <div id="messages">
            <div class="message bot"> Haloo! Saya adalah asisten karir. Apa yang ingin anda ketahui?</div>
        </div>
        <div id="input-area">
            <input type="text" id="user-input" placeholder="Ketik pertanyaan Anda..." autocomplete="off">
            <button id="send-button">-></button>
        </div>
    </div>

    <script>
        const messagesDiv = document.getElementById('messages');
        const userInput = document.getElementById('user-input');
        const sendButton = document.getElementById('send-button');

        function addMessage(role, content) {
            const messageElement = document.createElement('div');
            messageElement.classList.add('message', role);

            if (role === 'bot-loading') {
                const spinner = document.createElement('div');
                spinner.classList.add('spinner');
                messageElement.appendChild(spinner);
            } else {
                messageElement.textContent = content;
            }

            messagesDiv.appendChild(messageElement);
            messagesDiv.scrollTop = messagesDiv.scrollHeight;
            return messageElement;
        }

        async function sendMessage() {
            const question = userInput.value.trim();
            if (question === '') return;
            addMessage('user', question);
            userInput.value = '';

            const loadingIndicator = addMessage('bot-loading', '');

            try {
                const response = await fetch('/rag/ask', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                    },

                    body: JSON.stringify({
                        question: question
                    })
                });

                messagesDiv.removeChild(loadingIndicator);

                if (!response.ok) {
                    const errorData = await response.json();
                    addMessage('bot', `Maaf, terjadi error: ${errorData.error || response.statusText}`);
                    return;
                }

                const data = await response.json();
                addMessage('bot', data.reply);

            } catch (error) {
                messagesDiv.removeChild(loadingIndicator);
                addMessage('bot', 'Maaf, tidak dapat terhubung ke server. Periksa koneksi Anda.');
                console.error('Fetch error:', error);
            }
        }

        sendButton.addEventListener('click', sendMessage);
        userInput.addEventListener('keypress', function(event) {
            if (event.key === 'Enter') {
                sendMessage();
            }
        });
    </script>
</body>
</html>