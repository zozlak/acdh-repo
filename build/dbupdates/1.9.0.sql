BEGIN;

-- add language to the full_text_search table
ALTER TABLE full_text_search RENAME TO full_text_search_;
ALTER TABLE full_text_search_ DROP CONSTRAINT full_text_search_pkey;
DROP INDEX full_text_search_text_index;
CREATE TABLE public.full_text_search (
    ftsid bigint DEFAULT nextval('public.ftsid_seq'::regclass) NOT NULL,
    id bigint NOT NULL,
    property text NOT NULL,
    lang text,
    segments tsvector NOT NULL,
    raw text
);
INSERT INTO full_text_search (ftsid, id, property, lang, segments, raw)
    SELECT ftsid, id, property, null, segments, raw 
    FROM full_text_search_ 
    WHERE property = 'BINARY';
INSERT INTO full_text_search (ftsid, id, property, lang, segments, raw)
    SELECT nextval('ftsid_seq'), id, property, lang, to_tsvector('simple', value), value 
    FROM metadata m;
ALTER TABLE ONLY public.full_text_search ADD CONSTRAINT full_text_search_pkey PRIMARY KEY (ftsid);
CREATE INDEX full_text_search_text_index ON public.full_text_search USING gin (segments);
CREATE INDEX full_text_search_property_index ON public.full_text_search USING btree (property);
ALTER TABLE ONLY public.full_text_search ADD CONSTRAINT full_text_search_id_fkey FOREIGN KEY (id) REFERENCES public.resources(id) ON UPDATE CASCADE ON DELETE CASCADE DEFERRABLE INITIALLY DEFERRED;
DROP TABLE full_text_search_;

-- automatic syncing of the full_text_search table
CREATE FUNCTION public.sync_fts() RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP = 'DELETE' OR TG_OP = 'UPDATE' THEN
    DELETE FROM public.full_text_search WHERE (id, property, lang) = (OLD.id, OLD.property, OLD.lang);
  END IF;
  IF TG_OP = 'INSERT' OR TG_OP = 'UPDATE' THEN
    INSERT INTO public.full_text_search (ftsid, id, property, lang, segments, raw)
      VALUES (nextval('ftsid_seq'), NEW.id, NEW.property, NEW.lang, to_tsvector('simple', NEW.value), NEW.value);
  END IF;
  RETURN NULL;    
END;
$$;
CREATE TRIGGER fts_trigger1 AFTER INSERT OR UPDATE OR DELETE ON public.metadata FOR EACH ROW EXECUTE FUNCTION public.sync_fts();

CREATE FUNCTION public.truncate_fts() RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN
    DELETE FROM public.full_text_search WHERE property <> 'BINARY';
    RETURN NULL;
END;
$$;
CREATE TRIGGER fts_trigger2 AFTER TRUNCATE ON public.metadata FOR STATEMENT EXECUTE FUNCTION public.truncate_fts();

COMMIT;
