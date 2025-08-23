<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Subscriber_model extends CI_Model {
    protected $table = 'instagram_subscribers';

    public function __construct()
    {
        parent::__construct();
    }

    public function create_or_update($account_id, $ig_user_id, $data = [])
    {
        $existing = $this->get_by_ig_user_id($account_id, $ig_user_id);
        $now = date('Y-m-d H:i:s');
        if ($existing) {
            $update = array_merge($data, ['updated_at' => $now]);
            $this->db->where('id', $existing['id'])->update($this->table, $update);
            return $this->get_by_id($existing['id']);
        }
        $insert = array_merge(['account_id' => $account_id, 'ig_user_id' => $ig_user_id, 'created_at' => $now], $data);
        $this->db->insert($this->table, $insert);
        return $this->get_by_id($this->db->insert_id());
    }

    public function get_by_ig_user_id($account_id, $ig_user_id)
    {
        return $this->db->get_where($this->table, ['account_id' => $account_id, 'ig_user_id' => $ig_user_id])->row_array();
    }

    public function get_by_id($id)
    {
        return $this->db->get_where($this->table, ['id' => $id])->row_array();
    }
}