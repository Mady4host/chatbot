<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Instagram_bot_enhancers extends CI_Controller {

    public function __construct()
    {
        parent::__construct();
        $this->load->model('Account_model');
        $this->load->model('Subscriber_model');
        $this->load->model('Message_model');
        $this->load->library('Ig_rx_login');
        $this->load->helper('url');
        // TODO: load auth checks for admin
    }

    public function index()
    {
        // Minimal admin dashboard
        $data = [];
        $this->load->view('instagram/dashboard', $data);
    }

    public function connect_callback()
    {
        // OAuth callback handler skeleton
        // TODO: exchange code for tokens and save account record
        $code = $this->input->get('code');
        if (empty($code)) {
            show_error('Missing oauth code', 400);
            return;
        }

        // TODO: call Ig_rx_login->connect to exchange code
        // $result = $this->ig_rx_login->connect($code, $redirect_uri);

        // For scaffold, show placeholder
        echo "Connected (placeholder). Save tokens in Account_model in a real implementation.";
    }

    public function webhook()
    {
        // Handle both verification (GET) and incoming events (POST)
        if ($this->input->method() === 'get') {
            $challenge = $this->input->get('hub_challenge');
            $mode = $this->input->get('hub_mode');
            $verify_token = $this->input->get('hub_verify_token');
            $expected = $this->config->item('instagram_webhook_verify_token');
            if ($mode === 'subscribe' && $verify_token === $expected) {
                // return challenge
                header('Content-Type: text/plain');
                echo $challenge;
                return;
            }
            show_error('Forbidden', 403);
            return;
        }

        // POST: incoming event
        $raw = file_get_contents('php://input');
        $signature = $this->input->get_request_header('X-Hub-Signature-256');

        // Basic signature verification - delegated to library
        if (!$this->ig_rx_login->verifySignature($raw, $signature)) {
            log_message('error', 'Invalid signature on Instagram webhook');
            show_error('Invalid signature', 403);
            return;
        }

        $payload = json_decode($raw, true);
        if (empty($payload)) {
            log_message('error', 'Empty payload on Instagram webhook');
            http_response_code(400);
            echo "ok";
            return;
        }

        // Simple event handling: store incoming message(s)
        // TODO: extend to support mentions, comments, message types
        if (!empty($payload['entry'])) {
            foreach ($payload['entry'] as $entry) {
                // Keep minimal: iterate messaging-like structure if present
                if (!empty($entry['messaging'])) {
                    foreach ($entry['messaging'] as $event) {
                        // basic incoming message mapping
                        if (!empty($event['message'])) {
                            $sender = isset($event['sender']['id']) ? $event['sender']['id'] : null;
                            $text = isset($event['message']['text']) ? $event['message']['text'] : null;

                            // create or update subscriber and store message
                            $subscriber = $this->Subscriber_model->create_or_update($entry['id'] ?? null, $sender, ['name' => null]);
                            $this->Message_model->insert_message([
                                'account_id' => null,
                                'subscriber_id' => $subscriber ? $subscriber['id'] : null,
                                'direction' => 'in',
                                'message_type' => 'text',
                                'body' => $text
                            ]);
                        }
                    }
                }
            }
        }

        // respond quickly
        http_response_code(200);
        echo "EVENT_RECEIVED";
    }
}
