SELECT
    t.id,
    t.tipo,
    t.idioma_id,
    t.origen,
    t.traduccion
FROM _i18n t
WHERE t.deleted = 0
ORDER BY t.tipo ASC, t.origen ASC, t.idioma_id ASC;

SELECT
    t.id,
    t.tabla,
    t.campo,
    t.registro_id,
    aux.nombre AS valor_castellano,
    t.valor_varchar AS valor_traduccion_varchar,
    t.valor_txt AS valor_traduccion_text
FROM _i18n_fields t
         LEFT JOIN _menus aux ON (aux.id = t.registro_id)
WHERE t.deleted = 0 AND t.tabla = '_menus' AND aux.deleted = 0
ORDER BY t.tabla ASC, t.registro_id ASC;

SELECT x.* FROM (
    SELECT
        t.id,
        t.tabla,
        t.campo,
        t.registro_id,
        aux.`table` AS tabla_relacionada,
        aux.entity_name_one AS valor_castellano,
        t.valor_varchar AS valor_traduccion_varchar
    FROM _i18n_fields t
             LEFT JOIN __schema_tables aux ON (aux.id = t.registro_id)
    WHERE t.deleted = 0 AND t.tabla = '__schema_tables' AND t.campo = 'entity_name_one'
      AND aux.deleted = 0
    UNION
    SELECT
        t.id,
        t.tabla,
        t.campo,
        t.registro_id,
        aux.`table` AS tabla_relacionada,
        aux.entity_name_multiple AS valor_castellano,
        t.valor_varchar AS valor_traduccion_varchar
    FROM _i18n_fields t
             LEFT JOIN __schema_tables aux ON (aux.id = t.registro_id)
    WHERE t.deleted = 0 AND t.tabla = '__schema_tables' AND t.campo = 'entity_name_multiple'
      AND aux.deleted = 0
    ) x
ORDER BY x.tabla ASC, x.registro_id ASC,x.campo ASC


SELECT
    t.id,
    t.tabla,
    t.campo,
    t.registro_id,
    aux2.`table` AS nombre_tabla,
    aux.`field` AS nombre_campo,
    aux.label AS valor_castellano,
    t.valor_varchar AS valor_traduccion_varchar
FROM _i18n_fields t
         LEFT JOIN __schema_fields aux ON (aux.id = t.registro_id)
         LEFT JOIN __schema_tables aux2 ON (aux.table_id = aux2.id)
WHERE t.deleted = 0 AND t.tabla = '__schema_fields'AND t.campo = 'label'
  AND aux.deleted = 0 AND t.valor_varchar NOT LIKE '%#%'