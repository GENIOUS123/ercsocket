<?php
use Dotenv\Dotenv;

require_once __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$adminEmail = $_ENV['ADMIN_EMAIL'];
$socketUrl = $_ENV['ADMIN_SOCKET'];
$deviceId = $_ENV['DEVICE_ID'];
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ERC Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        /* Additional styling can be added here if needed */
    </style>
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
</head>

<body class="bg-gray-100 font-sans">
    <div class="max-w-4xl mx-auto px-4 py-8">
        <h1 class="text-3xl mb-4 text-center">ERC Admin Panel</h1>
        <div class="mb-4 flex">
            <select id="commandDropdown" class="mr-2 px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                <option value="pullErrorLog">Pull Error Log</option>
                <!-- Add more options as needed -->
            </select>
            <input type="text" id="emailInput" class="flex-1 bg-white border border-gray-300 rounded-lg px-4 py-2 mr-2" placeholder="Select a client to view error log">
            <button id="executeButton" class="bg-blue-500 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex-shrink-0">Execute</button>
        </div>
        <div id="statusMessage" class="mb-4 font-bold"></div>
        <table class="w-full mb-8">
            <thead>
                <tr>
                    <th class="bg-green-500 text-white px-4 py-2">Client DeviceId</th>
                    <th class="bg-green-500 text-white px-4 py-2">Client Email</th>
                    <th class="bg-green-500 text-white px-4 py-2">Status</th>
                </tr>
            </thead>
            <tbody id="clientsTable" class="bg-white">
                <!-- Client rows will be dynamically populated here -->
            </tbody>
        </table>
        <div id="messages" class="mb-8"></div>
    </div>

    <script>
        let conn;
        const clientsTable = document.getElementById('clientsTable');
        const emailInput = document.getElementById('emailInput');
        const executeButton = document.getElementById('executeButton');
        const statusMessage = document.getElementById('statusMessage');

        const email = '<?php echo $adminEmail; ?>';
        const deviceId = '<?php echo $deviceId; ?>';
        const socketUrl = '<?php echo $socketUrl; ?>';

        // Function to update clients table
        function updateClients(clients) {
            // Clear previous content
            clientsTable.innerHTML = '';
            // Loop through clients and create rows
            clients.forEach(client => {
                const row = document.createElement('tr');
                row.classList.add('cursor-pointer');
                row.onclick = () => emailInput.value = client.deviceId;
             row.innerHTML = `
               <td class="p-2 border-b border-gray-300">${client.deviceId}</td>
               <td class="p-2 border-b border-gray-300">${client.email || 'N/A'}</td>
               <td class="p-2 border-b border-gray-300 ${client.status === 'Loggedin' ? 'bg-green-200' : client.status === 'Online' ? 'bg-blue-200' : 'bg-red-200'}">${client.status}</td>
                 `;
                clientsTable.appendChild(row);
            });
        }

        // Function to display messages
        function displayMessage(data) {
            let messageData;
            try {
                messageData = JSON.parse(data);
            } catch (e) {
                console.log("Invalid JSON:", data);
                messageData = data;
            }
            
            const div = document.createElement('div');
            div.className = 'message';

            if (messageData.message && messageData.sentBy) {
                div.textContent = `${messageData.message} Sent by ${messageData.sentBy}`;
            } else if (messageData.sentBy === deviceId) {
                div.textContent = `${messageData.message} Sent by ${messageData.deviceId}`;
            } else {
                div.textContent = messageData;
            }

            $("#messages").append(div);

            if (messageData.errorlog) {
                const decodedData = atob(messageData.errorlog);
                const blob = new Blob([decodedData], {
                    type: 'text/plain'
                });
                const url = URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.href = url;
                link.download = `${messageData.sentBy}.txt`;
                link.textContent = 'Download Log';
                link.style.fontWeight = 'bold';
                link.style.marginLeft = '10px';
                div.appendChild(link);
            }
        }

        // Function to connect to WebSocket
        function connectWebSocket() {
            conn = new WebSocket(`${socketUrl}?deviceId=${deviceId}&email=${email}`);

            conn.onopen = function () {
                statusMessage.textContent = 'Connected';
                statusMessage.classList.add('text-green-600');
                updateClientsDisplay();
            };

            conn.onclose = function () {
                statusMessage.textContent = 'Disconnected';
                statusMessage.classList.add('text-red-600');
                setTimeout(connectWebSocket, 3000); // Reconnect after 3 seconds
            };

            conn.onerror = function (error) {
                console.error('WebSocket Error:', error);
            };

            conn.onmessage = function (e) {
                try {
                    const clients = JSON.parse(e.data);
                    if (Array.isArray(clients)) {
                        updateClients(clients);
                    } else {
                        displayMessage(e.data);
                    }
                } catch (error) {
                    displayMessage(e.data);
                }
            };
        }

        // Initial connection
        connectWebSocket();

        // Button click event
        executeButton.onclick = function () {
            const command = document.getElementById('commandDropdown').value;
            const deviceIdxx = emailInput.value.trim();
            if (email && conn && conn.readyState === WebSocket.OPEN) {
                const messageData = {
                    command: command,
                    sentTo:deviceIdxx
                };
                conn.send(JSON.stringify(messageData));
                console.log('Sent:', messageData);
            } else {
                alert('Please select a client and ensure the connection is active.');
            }
        };
        function countdownReconnect(seconds) {
                if (seconds > 0) {
                    statusMessage.textContent = `Disconnected. Reconnecting in ${seconds} seconds...`;
                    statusMessage.className = 'status-disconnected';
                    setTimeout(() => countdownReconnect(seconds - 1), 1000);
                } else {
                     connectWebSocket();
                }
            }
        
        function updateClientsDisplay() {
                $.ajax({
                    url: 'clients.php',
                    method: 'GET',
                    success: function(clients) {
                        updateClients(clients);
                    },
                    error: function(err) {
                        console.log('Error fetching clients:', err);
                    }
                });
            }
       setInterval(updateClientsDisplay, 5000);

    </script>
</body>

</html>
