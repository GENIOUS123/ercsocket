<?php
use Dotenv\Dotenv;

require_once __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

$adminEmail = getenv('ADMIN_EMAIL') ?: ($_ENV['ADMIN_EMAIL'] ?? 'itsupport@erceyecare.com');
$socketUrl = getenv('ADMIN_SOCKET') ?: ($_ENV['ADMIN_SOCKET'] ?? 'wss://monitor.erclens.com');
$deviceId = getenv('DEVICE_ID') ?: ($_ENV['DEVICE_ID'] ?? 'asxc1234567ERC004145665551wesASDVB123f0');
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ERC Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        .message {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 0.5rem;
            padding: 0.75rem 1rem;
            margin-bottom: 0.75rem;
        }
        .request-item {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 0.5rem;
            padding: 0.75rem 1rem;
            margin-bottom: 0.5rem;
        }
        .status-pill {
            display: inline-block;
            padding: 0.2rem 0.6rem;
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-left: 0.5rem;
        }
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-received { background: #dcfce7; color: #166534; }
        .status-error { background: #fee2e2; color: #991b1b; }
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
        <div id="requestTracker" class="mb-6"></div>
        <div class="mb-3 flex items-center justify-end gap-2">
            <label for="clientStatusFilter" class="text-sm font-semibold text-gray-700">Filter</label>
            <select id="clientStatusFilter" class="px-3 py-2 border border-gray-300 rounded-lg bg-white focus:outline-none focus:border-blue-500">
                <option value="">All</option>
                <option value="Online">Online</option>
                <option value="Loggedin">Logged In</option>
                <option value="Offline">Offline</option>
            </select>
        </div>
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
        let tabId = sessionStorage.getItem('ercAdminTabId');
        const clientsTable = document.getElementById('clientsTable');
        const emailInput = document.getElementById('emailInput');
        const executeButton = document.getElementById('executeButton');
        const statusMessage = document.getElementById('statusMessage');
        const clientStatusFilter = document.getElementById('clientStatusFilter');
        const requestTracker = document.getElementById('requestTracker');
        const messages = document.getElementById('messages');
        const pendingRequests = new Map();

        const email = '<?php echo $adminEmail; ?>';
        const deviceId = '<?php echo $deviceId; ?>';
        const socketUrl = '<?php echo $socketUrl; ?>';

        if (!tabId) {
            tabId = `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
            sessionStorage.setItem('ercAdminTabId', tabId);
        }

        function updateClients(clients) {
            clientsTable.innerHTML = '';
            const visibleClients = clients.filter(client => isValidDeviceId(client.deviceId));

            if (visibleClients.length === 0) {
                const row = document.createElement('tr');
                row.innerHTML = '<td class="p-3 text-center text-gray-500 border-b border-gray-300" colspan="3">No clients found.</td>';
                clientsTable.appendChild(row);
                return;
            }

            visibleClients.forEach(client => {
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

        function isValidDeviceId(value) {
            if (value === null || value === undefined) {
                return false;
            }

            const normalized = String(value).trim().toLowerCase();
            return normalized !== '' && !['undefined', 'null', 'nan'].includes(normalized);
        }

        function renderRequestTracker() {
            if (pendingRequests.size === 0) {
                requestTracker.innerHTML = '<div class="request-item">No active requests.</div>';
                return;
            }

            requestTracker.innerHTML = '';
            pendingRequests.forEach((request, requestId) => {
                const item = document.createElement('div');
                item.className = 'request-item';
                const stateClass = request.status === 'pending' ? 'status-pending' : request.status === 'download-ready' ? 'status-received' : 'status-error';
                item.innerHTML = `
                    <div class="flex items-center justify-between gap-2">
                        <div>
                            <strong>${request.targetDevice || 'Unknown target'}</strong>
                            <div class="text-sm text-gray-600">${request.message || 'Waiting for response'}</div>
                        </div>
                        <span class="status-pill ${stateClass}">${request.status || 'pending'}</span>
                    </div>
                `;
                requestTracker.appendChild(item);
            });
        }

        function updateRequestStatus(requestId, status, details = {}) {
            if (!requestId) {
                return;
            }
            const existing = pendingRequests.get(requestId) || {};
            const next = { ...existing, ...details, status };
            pendingRequests.set(requestId, next);
            renderRequestTracker();
        }

        function triggerDownload(payload) {
            if (!payload.errorlog) {
                return;
            }
            const decodedData = atob(payload.errorlog);
            const blob = new Blob([decodedData], { type: 'text/plain' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = `${payload.sentBy || payload.targetDevice || 'errorlog'}.txt`;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            setTimeout(() => URL.revokeObjectURL(url), 1000);
        }

        function displayMessage(data) {
            let messageData;
            try {
                messageData = JSON.parse(data);
            } catch (e) {
                console.log('Invalid JSON:', data);
                messageData = data;
            }

            if (messageData.type === 'request-status' && messageData.requestId) {
                updateRequestStatus(messageData.requestId, messageData.status || 'pending', {
                    targetDevice: messageData.targetDevice,
                    message: messageData.message || 'Waiting for response'
                });
            }

            const div = document.createElement('div');
            div.className = 'message';

            if (messageData.type === 'request-status') {
                div.textContent = `${messageData.message || 'Request update'} (${messageData.status || 'pending'})`;
            } else if (messageData.message && messageData.sentBy) {
                div.textContent = `${messageData.message} Sent by ${messageData.sentBy}`;
            } else if (messageData.sentBy === deviceId) {
                div.textContent = `${messageData.message || 'Message received'} Sent by ${messageData.deviceId}`;
            } else {
                div.textContent = typeof messageData === 'string' ? messageData : JSON.stringify(messageData);
            }

            messages.appendChild(div);

            if (messageData.errorlog) {
                updateRequestStatus(messageData.requestId, 'download-ready', {
                    targetDevice: messageData.targetDevice || messageData.sentBy,
                    message: 'Error log received'
                });
                triggerDownload(messageData);
                const link = document.createElement('a');
                link.href = '#';
                link.textContent = 'Downloaded';
                link.style.fontWeight = 'bold';
                link.style.marginLeft = '10px';
                div.appendChild(link);
            }
        }

        function connectWebSocket() {
            conn = new WebSocket(`${socketUrl}?deviceId=${encodeURIComponent(deviceId)}&email=${encodeURIComponent(email)}&role=admin&tabId=${encodeURIComponent(tabId)}`);

            conn.onopen = function () {
                statusMessage.textContent = 'Connected';
                statusMessage.classList.remove('text-red-600');
                statusMessage.classList.add('text-green-600');
                updateClientsDisplay();
            };

            conn.onclose = function () {
                statusMessage.textContent = 'Disconnected';
                statusMessage.classList.remove('text-green-600');
                statusMessage.classList.add('text-red-600');
                setTimeout(connectWebSocket, 3000);
            };

            conn.onerror = function (error) {
                console.error('WebSocket Error:', error);
            };

            conn.onmessage = function (e) {
                try {
                    const payload = JSON.parse(e.data);
                    if (Array.isArray(payload)) {
                        updateClients(payload);
                    } else {
                        if (payload.replyToTabId && payload.replyToTabId !== tabId) {
                            return;
                        }
                        displayMessage(e.data);
                    }
                } catch (error) {
                    displayMessage(e.data);
                }
            };
        }

        connectWebSocket();

        executeButton.onclick = function () {
            const command = document.getElementById('commandDropdown').value;
            const targetDevice = emailInput.value.trim();
            if (!targetDevice) {
                alert('Please select a client first.');
                return;
            }
            if (!email || !conn || conn.readyState !== WebSocket.OPEN) {
                alert('Please ensure the connection is active.');
                return;
            }

            const requestId = `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
            const messageData = {
                command: command,
                sentTo: targetDevice,
                requestId: requestId,
                tabId: tabId,
                sentBy: deviceId
            };

            pendingRequests.set(requestId, {
                targetDevice: targetDevice,
                status: 'pending',
                message: 'Request sent to device'
            });
            renderRequestTracker();
            conn.send(JSON.stringify(messageData));
            console.log('Sent:', messageData);
        };

        function updateClientsDisplay() {
            const status = clientStatusFilter.value;
            $.ajax({
                url: status ? `clients.php?status=${encodeURIComponent(status)}` : 'clients.php',
                method: 'GET',
                success: function(clients) {
                    updateClients(clients);
                },
                error: function(err) {
                    console.log('Error fetching clients:', err);
                }
            });
        }

        clientStatusFilter.onchange = updateClientsDisplay;
        setInterval(updateClientsDisplay, 5000);
    </script>
</body>

</html>
