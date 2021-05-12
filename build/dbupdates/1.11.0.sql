BEGIN;
SELECT * FROM information_schema.role_table_grants WHERE table_schema = 'public' and table_name = 'full_text_search';
CREATE TEMPORARY TABLE _ftsbin AS 
    SELECT ftsid, id, segments, raw FROM full_text_search WHERE property = 'BINARY';
DROP TABLE full_text_search;
CREATE TABLE full_text_search (
    ftsid bigint DEFAULT nextval('public.ftsid_seq') NOT NULL,
    id bigint,
    mid bigint,
    segments tsvector NOT NULL,
    raw text
);
INSERT INTO full_text_search SELECT ftsid, id, null, segments, raw FROM _ftsbin;
INSERT INTO full_text_search SELECT nextval('public.ftsid_seq'), null, mid, to_tsvector('simple', value), value FROM metadata;
ALTER TABLE ONLY full_text_search ADD CONSTRAINT full_text_search_pkey PRIMARY KEY (ftsid);
CREATE INDEX full_text_search_mid_index ON full_text_search USING btree (mid);
CREATE INDEX full_text_search_text_index ON full_text_search USING gin (segments);
ALTER TABLE ONLY full_text_search ADD CONSTRAINT full_text_search_id_fkey FOREIGN KEY (id) REFERENCES resources(id) ON UPDATE CASCADE ON DELETE CASCADE DEFERRABLE INITIALLY DEFERRED;
ALTER TABLE ONLY full_text_search ADD CONSTRAINT full_text_search_mid_fkey FOREIGN KEY (mid) REFERENCES metadata(mid) ON UPDATE CASCADE ON DELETE CASCADE DEFERRABLE INITIALLY DEFERRED;

DROP TRIGGER fts_trigger1 ON metadata;
DROP FUNCTION sync_fts;
DROP TRIGGER fts_trigger2 ON metadata;
CREATE OR REPLACE FUNCTION public.truncate_fts() RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN
    DELETE FROM public.full_text_search WHERE mid IS NOT NULL;
    RETURN NULL;
END;
$$;
CREATE TRIGGER fts_trigger_truncate AFTER TRUNCATE ON public.metadata FOR STATEMENT EXECUTE FUNCTION public.truncate_fts();
CREATE FUNCTION fts_insert() RETURNS TRIGGER LANGUAGE plpgsql AS $$  
BEGIN
  INSERT INTO public.full_text_search (ftsid, id, mid, segments, raw)
    VALUES (nextval('ftsid_seq'), null, NEW.mid, to_tsvector('simple', NEW.value), NEW.value);
  RETURN NULL;    
END;
$$;
CREATE TRIGGER fts_trigger_insert AFTER INSERT ON public.metadata FOR EACH ROW EXECUTE FUNCTION public.fts_insert();
CREATE FUNCTION fts_update() RETURNS TRIGGER LANGUAGE plpgsql AS $$  
BEGIN
  UPDATE public.full_text_search
    SET segments = to_tsvector('simple', NEW.value), raw = NEW.value
    WHERE mid = NEW.mid;
  RETURN NULL;    
END;
$$;
CREATE TRIGGER fts_trigger_update AFTER UPDATE ON public.metadata FOR EACH ROW EXECUTE FUNCTION public.fts_update();

CREATE OR REPLACE PROCEDURE public.delete_collection(resource_id bigint, rel_prop text) AS $$
DECLARE
  cnt int;
BEGIN
  DROP TABLE IF EXISTS __resToDel;
  CREATE TEMPORARY TABLE __resToDel AS SELECT * FROM get_relatives(resource_id, rel_prop);

  DROP TABLE IF EXISTS __resConflict;
  CREATE TEMPORARY TABLE __resConflict AS
    SELECT *
    FROM relations r 
    WHERE 
      EXISTS (SELECT 1 FROM __resToDel WHERE r.target_id = id) 
      AND NOT EXISTS (SELECT 1 FROM __resToDel WHERE r.id = id);

  SELECT INTO cnt count(*) FROM __resConflict;
  IF cnt > 0 THEN
    RAISE NOTICE 'Aborting deletion as there are triples pointing to resources being removed - you can find them in the __resconflict temporary table';
  ELSE
    DROP TRIGGER metadata_trigger ON metadata;
    DROP TRIGGER metadata_trigger ON relations;
    DROP TRIGGER metadata_trigger ON identifiers;
    ALTER TABLE metadata DROP CONSTRAINT metadata_id_fkey;
    ALTER TABLE relations DROP CONSTRAINT relations_id_fkey;
    ALTER TABLE relations DROP CONSTRAINT relations_target_id_fkey;
    ALTER TABLE identifiers DROP CONSTRAINT identifiers_id_fkey;
    ALTER TABLE metadata_history DROP CONSTRAINT metadata_history_id_fkey;
    ALTER TABLE full_text_search DROP CONSTRAINT full_text_search_id_fkey;
    ALTER TABLE full_text_search DROP CONSTRAINT full_text_search_mid_fkey;
    DELETE FROM metadata_history WHERE id IN (SELECT id FROM __resToDel);
    DELETE FROM full_text_search WHERE mid IN (SELECT mid FROM metadata JOIN __resToDel USING (id));
    DELETE FROM full_text_search WHERE id IN (SELECT id FROM __resToDel);
    DELETE FROM relations WHERE id IN (SELECT id FROM __resToDel);
    DELETE FROM identifiers WHERE id IN (SELECT id FROM __resToDel);
    DELETE FROM metadata WHERE id IN (SELECT id FROM __resToDel);
    DELETE FROM resources WHERE id IN (SELECT id FROM __resToDel);
    ALTER TABLE metadata ADD FOREIGN KEY (id) REFERENCES resources(id) ON UPDATE CASCADE ON DELETE CASCADE DEFERRABLE INITIALLY DEFERRED;
    ALTER TABLE relations ADD FOREIGN KEY (id) REFERENCES resources(id) ON UPDATE CASCADE ON DELETE CASCADE DEFERRABLE INITIALLY DEFERRED;
    ALTER TABLE relations ADD FOREIGN KEY (target_id) REFERENCES resources(id) DEFERRABLE INITIALLY DEFERRED;
    ALTER TABLE identifiers ADD FOREIGN KEY (id) REFERENCES resources(id) ON UPDATE CASCADE ON DELETE CASCADE DEFERRABLE INITIALLY DEFERRED;
    ALTER TABLE metadata_history ADD FOREIGN KEY (id) REFERENCES resources(id) ON UPDATE CASCADE ON DELETE CASCADE DEFERRABLE INITIALLY DEFERRED;
    ALTER TABLE full_text_search ADD FOREIGN KEY (id) REFERENCES resources(id) ON UPDATE CASCADE ON DELETE CASCADE DEFERRABLE INITIALLY DEFERRED;
    ALTER TABLE full_text_search ADD FOREIGN KEY (mid) REFERENCES metadata(mid) ON UPDATE CASCADE ON DELETE CASCADE DEFERRABLE INITIALLY DEFERRED;
    CREATE TRIGGER metadata_trigger AFTER UPDATE OR DELETE ON public.metadata FOR EACH ROW EXECUTE FUNCTION public.copy_metadata();
    CREATE TRIGGER metadata_trigger AFTER UPDATE OR DELETE ON public.identifiers FOR EACH ROW EXECUTE FUNCTION public.copy_identifiers();
    CREATE TRIGGER metadata_trigger AFTER UPDATE OR DELETE ON public.relations FOR EACH ROW EXECUTE FUNCTION public.copy_relations();
    RAISE NOTICE 'Deleted resources''s ids can be found in __restodel temporary table';
  END IF;
END;
$$ LANGUAGE plpgsql;

COMMIT;
