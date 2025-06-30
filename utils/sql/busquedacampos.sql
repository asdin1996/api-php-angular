SELECT
	f.id AS field_id,
	t.id AS table_id,
	t.`table` AS `table_name`,
	f.`type` AS tipo_campo,
	f.label AS label
FROM __schema_fields f
LEFT JOIN __schema_tables t ON (t.id = f.table_id)
WHERE f.deleted = 0 AND t.deleted = 0;