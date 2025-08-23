<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Account_model extends CI_Model {

    protected $table = 'instagram_accounts';

    public function __construct()
    {
        parent::__construct();
    }

    public function create($data)
    {
        $this->db->insert($this->table, $data);
        return $this->db->insert_id();
    }

    public function update_token($id, $tokenData)
    {
        $this->db->where('id', $id);
        return $this->db->update($this->table, $tokenData);
    }

    public function get_by_id($id)
    {
        return $this->db->get_where($this->table, ['id' => $id])->row_array();
    }

    public function get_by_ig_user_id($ig_user_id)
    {
        return $this->db->get_where($this->table, ['ig_user_id' => $ig_user_id])->row_array();
    }
}