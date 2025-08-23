<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Campaign_model extends CI_Model {
    protected $table = 'instagram_campaigns';

    public function __construct()
    {
        parent::__construct();
    }

    public function create($data)
    {
        $this->db->insert($this->table, $data);
        return $this->db->insert_id();
    }

    public function list_by_account($account_id)
    {
        return $this->db->get_where($this->table, ['account_id' => $account_id])->result_array();
    }
}