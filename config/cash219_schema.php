<?php
// Exact additive Cash210/219 contracts; schema installation does not enable handoff.
return array (
  'contract_version' => 'cash-refunds-219-1',
  'required_columns' => 
  array (
    'refund_receipts' => 
    array (
      'cash_sales_receipt_id' => 
      array (
        'data_type' => 'bigint',
        'unsigned' => true,
        'nullable' => true,
      ),
      'cash_sales_journal_id' => 
      array (
        'data_type' => 'bigint',
        'unsigned' => true,
        'nullable' => true,
      ),
      'cash_refund_uuid' => 
      array (
        'data_type' => 'char',
        'column_type' => 'char(36)',
        'nullable' => true,
      ),
      'cash_refund_intent_hash' => 
      array (
        'data_type' => 'char',
        'column_type' => 'char(64)',
        'nullable' => true,
      ),
      'cash_refund_amount' => 
      array (
        'data_type' => 'decimal',
        'column_type' => 'decimal(24,6)',
        'nullable' => true,
      ),
      'cash_refund_snapshot' => 
      array (
        'data_type' => 'longtext',
        'nullable' => true,
      ),
    ),
    'finance_cash_refund_demands' => 
    array (
      'id' => 
      array (
        'data_type' => 'bigint',
        'unsigned' => true,
        'nullable' => false,
      ),
      'organization_id' => 
      array (
        'data_type' => 'bigint',
        'unsigned' => true,
        'nullable' => false,
      ),
      'source_document_id' => 
      array (
        'data_type' => 'bigint',
        'unsigned' => true,
        'nullable' => false,
      ),
      'source_journal_id' => 
      array (
        'data_type' => 'bigint',
        'unsigned' => true,
        'nullable' => false,
      ),
      'refund_receipt_id' => 
      array (
        'data_type' => 'bigint',
        'unsigned' => true,
        'nullable' => false,
      ),
      'actor_id' => 
      array (
        'data_type' => 'bigint',
        'unsigned' => true,
        'nullable' => false,
      ),
      'refund_journal_id' => 
      array (
        'data_type' => 'bigint',
        'unsigned' => true,
        'nullable' => true,
      ),
      'reverse_actor_id' => 
      array (
        'data_type' => 'bigint',
        'unsigned' => true,
        'nullable' => true,
      ),
      'organization_mapping_uuid' => 
      array (
        'data_type' => 'char',
        'column_type' => 'char(36)',
        'nullable' => false,
      ),
      'request_uuid' => 
      array (
        'data_type' => 'char',
        'column_type' => 'char(36)',
        'nullable' => false,
      ),
      'operation_uuid' => 
      array (
        'data_type' => 'char',
        'column_type' => 'char(36)',
        'nullable' => false,
      ),
      'source_revision' => 
      array (
        'data_type' => 'char',
        'column_type' => 'char(64)',
        'nullable' => false,
      ),
      'payload_hash' => 
      array (
        'data_type' => 'char',
        'column_type' => 'char(64)',
        'nullable' => false,
      ),
      'hold_fingerprint' => 
      array (
        'data_type' => 'char',
        'column_type' => 'char(64)',
        'nullable' => true,
      ),
      'payload' => 
      array (
        'data_type' => 'longtext',
        'nullable' => false,
      ),
      'financial_plan' => 
      array (
        'data_type' => 'longtext',
        'nullable' => false,
      ),
      'state' => 
      array (
        'data_type' => 'varchar',
        'column_type' => 'varchar(32)',
        'nullable' => false,
      ),
      'recovery_attempts' => 
      array (
        'data_type' => 'smallint',
        'unsigned' => true,
        'nullable' => false,
      ),
      'recovery_terminal' => 
      array (
        'data_type' => 'tinyint',
        'nullable' => false,
      ),
      'last_recovery_error' => 
      array (
        'data_type' => 'varchar',
        'column_type' => 'varchar(64)',
        'nullable' => true,
      ),
      'next_recovery_at' => 
      array (
        'data_type' => 'timestamp',
        'nullable' => true,
      ),
      'created_at' => 
      array (
        'data_type' => 'timestamp',
        'nullable' => true,
      ),
      'updated_at' => 
      array (
        'data_type' => 'timestamp',
        'nullable' => true,
      ),
    ),
    'stock_cash_refund_demands' => 
    array (
      'id' => 
      array (
        'data_type' => 'bigint',
        'unsigned' => true,
        'nullable' => false,
      ),
      'organization_id' => 
      array (
        'data_type' => 'bigint',
        'unsigned' => true,
        'nullable' => false,
      ),
      'request_id' => 
      array (
        'data_type' => 'bigint',
        'unsigned' => true,
        'nullable' => false,
      ),
      'source_document_id' => 
      array (
        'data_type' => 'bigint',
        'unsigned' => true,
        'nullable' => false,
      ),
      'source_journal_id' => 
      array (
        'data_type' => 'bigint',
        'unsigned' => true,
        'nullable' => false,
      ),
      'refund_receipt_id' => 
      array (
        'data_type' => 'bigint',
        'unsigned' => true,
        'nullable' => false,
      ),
      'actor_id' => 
      array (
        'data_type' => 'bigint',
        'unsigned' => true,
        'nullable' => false,
      ),
      'refund_journal_id' => 
      array (
        'data_type' => 'bigint',
        'unsigned' => true,
        'nullable' => true,
      ),
      'reverse_actor_id' => 
      array (
        'data_type' => 'bigint',
        'unsigned' => true,
        'nullable' => true,
      ),
      'organization_mapping_uuid' => 
      array (
        'data_type' => 'char',
        'column_type' => 'char(36)',
        'nullable' => false,
      ),
      'request_uuid' => 
      array (
        'data_type' => 'char',
        'column_type' => 'char(36)',
        'nullable' => false,
      ),
      'operation_uuid' => 
      array (
        'data_type' => 'char',
        'column_type' => 'char(36)',
        'nullable' => false,
      ),
      'source_revision' => 
      array (
        'data_type' => 'char',
        'column_type' => 'char(64)',
        'nullable' => false,
      ),
      'payload_hash' => 
      array (
        'data_type' => 'char',
        'column_type' => 'char(64)',
        'nullable' => false,
      ),
      'hold_fingerprint' => 
      array (
        'data_type' => 'char',
        'column_type' => 'char(64)',
        'nullable' => false,
      ),
      'payload' => 
      array (
        'data_type' => 'longtext',
        'nullable' => false,
      ),
      'state' => 
      array (
        'data_type' => 'varchar',
        'column_type' => 'varchar(32)',
        'nullable' => false,
      ),
      'created_at' => 
      array (
        'data_type' => 'timestamp',
        'nullable' => true,
      ),
      'updated_at' => 
      array (
        'data_type' => 'timestamp',
        'nullable' => true,
      ),
    ),
    'stock_cash_partial_returns' => 
    array (
      'id' => 
      array (
        'data_type' => 'bigint',
        'unsigned' => true,
        'nullable' => false,
      ),
      'organization_id' => 
      array (
        'data_type' => 'bigint',
        'unsigned' => true,
        'nullable' => false,
      ),
      'actor_id' => 
      array (
        'data_type' => 'bigint',
        'unsigned' => true,
        'nullable' => false,
      ),
      'request_id' => 
      array (
        'data_type' => 'bigint',
        'unsigned' => true,
        'nullable' => false,
      ),
      'shipment_id' => 
      array (
        'data_type' => 'bigint',
        'unsigned' => true,
        'nullable' => false,
      ),
      'sales_return_id' => 
      array (
        'data_type' => 'bigint',
        'unsigned' => true,
        'nullable' => false,
      ),
      'organization_mapping_uuid' => 
      array (
        'data_type' => 'char',
        'column_type' => 'char(36)',
        'nullable' => false,
      ),
      'operation_uuid' => 
      array (
        'data_type' => 'char',
        'column_type' => 'char(36)',
        'nullable' => false,
      ),
      'payload_hash' => 
      array (
        'data_type' => 'char',
        'column_type' => 'char(64)',
        'nullable' => false,
      ),
      'payload' => 
      array (
        'data_type' => 'longtext',
        'nullable' => false,
      ),
      'state' => 
      array (
        'data_type' => 'varchar',
        'column_type' => 'varchar(32)',
        'nullable' => false,
      ),
      'created_at' => 
      array (
        'data_type' => 'timestamp',
        'nullable' => true,
      ),
      'updated_at' => 
      array (
        'data_type' => 'timestamp',
        'nullable' => true,
      ),
    ),
    'cash_notification_outbox' => 
    array (
      'id' => 
      array (
        'data_type' => 'bigint',
        'unsigned' => true,
        'nullable' => false,
      ),
      'organization_id' => 
      array (
        'data_type' => 'bigint',
        'unsigned' => true,
        'nullable' => false,
      ),
      'request_id' => 
      array (
        'data_type' => 'bigint',
        'unsigned' => true,
        'nullable' => false,
      ),
      'transition_fingerprint' => 
      array (
        'data_type' => 'char',
        'column_type' => 'char(64)',
        'nullable' => false,
      ),
      'state' => 
      array (
        'data_type' => 'varchar',
        'column_type' => 'varchar(20)',
        'nullable' => false,
      ),
      'attempts' => 
      array (
        'data_type' => 'int',
        'unsigned' => true,
        'nullable' => false,
      ),
      'retry_at' => 
      array (
        'data_type' => 'timestamp',
        'nullable' => true,
      ),
      'lease_until' => 
      array (
        'data_type' => 'timestamp',
        'nullable' => true,
      ),
      'delivered_at' => 
      array (
        'data_type' => 'timestamp',
        'nullable' => true,
      ),
      'created_at' => 
      array (
        'data_type' => 'timestamp',
        'nullable' => true,
      ),
      'updated_at' => 
      array (
        'data_type' => 'timestamp',
        'nullable' => true,
      ),
      'lease_token' => 
      array (
        'data_type' => 'char',
        'column_type' => 'char(36)',
        'nullable' => true,
      ),
      'notification_thread_id' => 
      array (
        'data_type' => 'char',
        'column_type' => 'char(36)',
        'nullable' => true,
      ),
      'last_error' => 
      array (
        'data_type' => 'varchar',
        'column_type' => 'varchar(80)',
        'nullable' => true,
      ),
    ),
  ),
  'index_definitions' => 
  array (
    'refund_receipts' => 
    array (
      'refund_cash_uuid_org_unique' => 
      array (
        'unique' => true,
        'columns' => 
        array (
          0 => 'organization_id',
          1 => 'cash_refund_uuid',
        ),
      ),
      'refund_cash_source_org_idx' => 
      array (
        'unique' => false,
        'columns' => 
        array (
          0 => 'organization_id',
          1 => 'cash_sales_receipt_id',
        ),
      ),
    ),
    'finance_cash_refund_demands' => 
    array (
      'fcrd_org_operation_unique' => 
      array (
        'unique' => true,
        'columns' => 
        array (
          0 => 'organization_id',
          1 => 'operation_uuid',
        ),
      ),
      'fcrd_org_refund_unique' => 
      array (
        'unique' => true,
        'columns' => 
        array (
          0 => 'organization_id',
          1 => 'refund_receipt_id',
        ),
      ),
      'fcrd_request_state_index' => 
      array (
        'unique' => false,
        'columns' => 
        array (
          0 => 'organization_id',
          1 => 'request_uuid',
          2 => 'state',
        ),
      ),
    ),
    'stock_cash_refund_demands' => 
    array (
      'scrd_org_operation_unique' => 
      array (
        'unique' => true,
        'columns' => 
        array (
          0 => 'organization_id',
          1 => 'operation_uuid',
        ),
      ),
      'scrd_org_refund_unique' => 
      array (
        'unique' => true,
        'columns' => 
        array (
          0 => 'organization_id',
          1 => 'refund_receipt_id',
        ),
      ),
      'scrd_request_state_index' => 
      array (
        'unique' => false,
        'columns' => 
        array (
          0 => 'organization_id',
          1 => 'request_id',
          2 => 'state',
        ),
      ),
    ),
    'stock_cash_partial_returns' => 
    array (
      'scpr_operation_unique' => 
      array (
        'unique' => true,
        'columns' => 
        array (
          0 => 'organization_id',
          1 => 'operation_uuid',
        ),
      ),
      'scpr_return_unique' => 
      array (
        'unique' => true,
        'columns' => 
        array (
          0 => 'organization_id',
          1 => 'sales_return_id',
        ),
      ),
    ),
    'cash_notification_outbox' => 
    array (
      'cash_notification_transition_unique' => 
      array (
        'unique' => true,
        'columns' => 
        array (
          0 => 'organization_id',
          1 => 'request_id',
          2 => 'transition_fingerprint',
        ),
      ),
      'cash_notification_due' => 
      array (
        'unique' => false,
        'columns' => 
        array (
          0 => 'organization_id',
          1 => 'state',
          2 => 'retry_at',
        ),
      ),
    ),
  ),
);
