<?php $this->view('partials/head')?>

<div class="container">
    <div class="row">
        <div class="col-lg-8">
            <h3>AppleCare Admin</h3>
            <p>Run the AppleCare sync script and inspect the output.</p>

            <div class="form-group">
                <button id="sync-applecare" class="btn btn-primary">
                    <i class="fa fa-refresh"></i> Run AppleCare Sync
                </button>
                <span id="sync-status" class="text-muted" style="margin-left:8px;"></span>
            </div>

            <div class="panel panel-default">
                <div class="panel-heading">
                    <strong>Sync Output</strong>
                </div>
                <div class="panel-body">
                    <pre id="sync-output" style="white-space:pre-wrap;min-height:120px;">Waiting to run…</pre>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function(){
    var $btn = $('#sync-applecare');
    var $status = $('#sync-status');
    var $output = $('#sync-output');
    var eventSource = null;
    var outputBuffer = '';

    function appendOutput(text){
        if (text) {
            outputBuffer += text + '\n';
            $output.text(outputBuffer);
            // Auto-scroll to bottom
            $output.scrollTop($output[0].scrollHeight);
        }
    }

    function clearOutput(){
        outputBuffer = '';
        $output.text('');
    }

    function stopSync(){
        if (eventSource) {
            eventSource.close();
            eventSource = null;
        }
        $btn.prop('disabled', false);
    }

    $btn.on('click', function(){
        // Prevent multiple simultaneous syncs
        if (eventSource) {
            return;
        }

        $btn.prop('disabled', true);
        $status.text('Running…');
        clearOutput();
        appendOutput('Starting AppleCare sync...\n');

        // Use Server-Sent Events for real-time streaming
        var url = appUrl + '/module/applecare/sync?stream=1';
        eventSource = new EventSource(url);

        eventSource.onopen = function() {
            appendOutput('Connected to sync process...\n');
        };

        eventSource.addEventListener('output', function(e) {
            var data = e.data;
            // Unescape newlines
            data = data.replace(/\\n/g, '\n');
            appendOutput(data);
        });

        eventSource.addEventListener('error', function(e) {
            var data = e.data;
            // Unescape newlines
            data = data.replace(/\\n/g, '\n');
            appendOutput('ERROR: ' + data);
        });

        eventSource.addEventListener('complete', function(e) {
            var data = JSON.parse(e.data);
            appendOutput('\n================================================\n');
            appendOutput('Sync Complete\n');
            appendOutput('Exit code: ' + data.exit_code + '\n');
            appendOutput('================================================\n');
            
            $status.text(data.success ? 'Finished' : 'Finished with errors');
            stopSync();
        });

        eventSource.onerror = function(e) {
            if (eventSource.readyState === EventSource.CLOSED) {
                // Connection closed - sync completed or error occurred
                if ($status.text() === 'Running…') {
                    // Unexpected closure
                    appendOutput('\nConnection closed unexpectedly.\n');
                    $status.text('Connection closed');
                }
                stopSync();
            } else {
                // Connection error
                appendOutput('\nConnection error occurred.\n');
                $status.text('Connection error');
                stopSync();
            }
        };
    });
})();
</script>

<?php $this->view('partials/foot'); ?>