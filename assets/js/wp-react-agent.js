// File: wp-react-agent/assets/js/wp-react-agent.js
window.wpReactAgent = {
    run: function(query) {
        console.log(`WP ReAct Agent: Sending query... "${query}"`);

        if (!wpReactAgentData || !wpReactAgentData.ajax_url || !wpReactAgentData.nonce) {
            console.error('WP ReAct Agent: Missing localization data (ajax_url or nonce).');
            return Promise.reject('Missing localization data.');
        }

        const formData = new FormData();
        formData.append('action', 'react_agent_run');
        formData.append('nonce', wpReactAgentData.nonce);
        formData.append('query', query);

        return fetch(wpReactAgentData.ajax_url, {
            method: 'POST',
            body: formData
        })
        .then(response => {
             // Improved error handling for JSON and non-JSON responses
            if (!response.ok) {
                return response.json()
                    .then(err => { // Try parsing JSON error first
                        const message = (err.data && err.data.message) ? err.data.message :
                                        (err.data && typeof err.data === 'string') ? err.data :
                                        'HTTP error ' + response.status;
                        throw new Error(message);
                    })
                    .catch(() => { // Fallback if response wasn't JSON
                        throw new Error(`HTTP error ${response.status}: ${response.statusText}`);
                    });
            }
             // Check if response is potentially empty (e.g., 204 No Content)
            if (response.status === 204 || response.headers.get('content-length') === '0') {
                return null; // Or handle as appropriate
            }
            return response.json(); // Only parse JSON if response is OK and has content
        })
        .then(result => {
             if (result === null) { // Handle empty but OK responses
                 console.warn('WP ReAct Agent: Received empty but successful response.');
                 return null; // Or some default success object
             }
            if (result.success) {
                console.log('%cWP ReAct Agent: Success!', 'color: green; font-weight: bold;');
                console.log('%cFinal Answer:', 'font-weight: bold;', result.data.answer);
                console.log('--- Full Transcript ---');
                console.log(result.data.transcript);
                return result.data;
            } else {
                // Handle structured WP JSON errors
                const errorMessage = (result.data && result.data.message) ? result.data.message :
                                     (result.data && typeof result.data === 'string') ? result.data :
                                     'Unknown error occurred.';
                console.error('%cWP ReAct Agent: Error!', 'color: red; font-weight: bold;');
                console.error('Message:', errorMessage);
                if (result.data && result.data.code) {
                    console.error('Code:', result.data.code);
                }
                console.log('--- Full Transcript (if available) ---');
                console.log(result.data && result.data.transcript ? result.data.transcript : '(No transcript)');
                throw new Error(errorMessage);
            }
        })
        .catch(error => {
            console.error('%cWP ReAct Agent: Fetch failed.', 'color: red; font-weight: bold;', error);
            throw error;
        });
    }
};

console.log("WP ReAct Agent loaded. Use `wpReactAgent.run('Your query here')` to start.");