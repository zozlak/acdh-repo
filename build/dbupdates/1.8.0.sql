BEGIN;

DROP FUNCTION get_relatives_metadata(bigint, text, int, int);
CREATE OR REPLACE FUNCTION public.get_relatives_metadata(res_id bigint, rel_prop text, max_depth_up integer DEFAULT 999999, max_depth_down integer default -999999, neighbors bool default true) RETURNS SETOF public.metadata_view
    LANGUAGE sql
    AS $$
WITH RECURSIVE ids(id, n, m) AS (
  SELECT res_id, 0, ARRAY[res_id] FROM resources WHERE id = res_id AND state = 'active'
UNION
  SELECT
    CASE r.target_id WHEN ids.id THEN r.id ELSE r.target_id END,
    CASE r.target_id WHEN ids.id THEN ids.n + 1 ELSE ids.n - 1 END,
    CASE r.target_id WHEN ids.id THEN ARRAY[r.id] ELSE ARRAY[r.target_id] END || m
  FROM 
    relations r 
    JOIN ids ON (ids.n >= 0 AND ids.n < max_depth_up AND r.target_id = ids.id AND NOT r.id = ANY(ids.m)) OR (ids.n <= 0 AND ids.n > max_depth_down AND r.id = ids.id AND NOT r.target_id = ANY(ids.m))
  WHERE property = rel_prop
), ids2 AS (
    SELECT id FROM ids
  UNION
    SELECT target_id AS id FROM ids JOIN relations r ON ids.id = r.id AND neighbors
)
    SELECT id, property, type, lang, value
    FROM metadata JOIN ids2 USING (id)
  UNION
    SELECT id, null AS property, 'ID' AS type, null AS lang, ids AS value
    FROM identifiers JOIN ids2 USING (id)
  UNION
    SELECT id, property, 'REL' AS type, null AS lang, target_id::text AS value
    FROM relations r JOIN ids2 USING (id)
;
$$;

COMMIT;
