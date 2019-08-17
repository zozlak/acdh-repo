--
-- PostgreSQL database dump
--

-- Dumped from database version 11.5 (Ubuntu 11.5-1.pgdg19.04+1)
-- Dumped by pg_dump version 11.5 (Ubuntu 11.5-1.pgdg19.04+1)

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

BEGIN;

--
-- Name: get_allowed_resources(text, json); Type: FUNCTION; Schema: public; Owner: zozlak
--

CREATE FUNCTION public.get_allowed_resources(acl_prop text, roles json) RETURNS TABLE(id bigint)
    LANGUAGE sql STABLE
    AS $$
        select distinct id
        from metadata
        where property = acl_prop and value in (select json_array_elements_text(roles))
;
$$;


ALTER FUNCTION public.get_allowed_resources(acl_prop text, roles json) OWNER TO zozlak;

SET default_tablespace = '';

SET default_with_oids = false;

--
-- Name: identifiers; Type: TABLE; Schema: public; Owner: zozlak
--

CREATE TABLE public.identifiers (
    ids text NOT NULL,
    id bigint
);


ALTER TABLE public.identifiers OWNER TO zozlak;

--
-- Name: mid_seq; Type: SEQUENCE; Schema: public; Owner: zozlak
--

CREATE SEQUENCE public.mid_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.mid_seq OWNER TO zozlak;

--
-- Name: metadata; Type: TABLE; Schema: public; Owner: zozlak
--

CREATE TABLE public.metadata (
    mid integer DEFAULT nextval('public.mid_seq'::regclass) NOT NULL,
    id bigint NOT NULL,
    property text NOT NULL,
    type text NOT NULL,
    lang text NOT NULL,
    value_n double precision,
    value_t timestamp without time zone,
    value text
);


ALTER TABLE public.metadata OWNER TO zozlak;

--
-- Name: relations; Type: TABLE; Schema: public; Owner: zozlak
--

CREATE TABLE public.relations (
    id bigint NOT NULL,
    target_id integer NOT NULL,
    property text NOT NULL
);


ALTER TABLE public.relations OWNER TO zozlak;

--
-- Name: metadata_view; Type: VIEW; Schema: public; Owner: zozlak
--

CREATE VIEW public.metadata_view AS
 SELECT metadata.id,
    metadata.property,
    metadata.type,
    metadata.lang,
    metadata.value
   FROM public.metadata
UNION
 SELECT identifiers.id,
    NULL::text AS property,
    'ID'::text AS type,
    NULL::text AS lang,
    identifiers.ids AS value
   FROM public.identifiers
UNION
 SELECT r.id,
    r.property,
    'REL'::text AS type,
    NULL::text AS lang,
    (r.target_id)::text AS value
   FROM public.relations r;


ALTER TABLE public.metadata_view OWNER TO zozlak;

--
-- Name: get_neighbors_metadata(bigint, text); Type: FUNCTION; Schema: public; Owner: zozlak
--

CREATE FUNCTION public.get_neighbors_metadata(res_id bigint, rel_prop text) RETURNS SETOF public.metadata_view
    LANGUAGE sql
    AS $$
 with ids as (
    select res_id as id
  union
    select id from relations where property = rel_prop and target_id = res_id
  union
    select target_id as id from relations where id = res_id
)
            SELECT id, property, type, lang, value
            FROM metadata JOIN ids USING (id)
          UNION
            SELECT id, null AS property, 'ID' AS type, null AS lang, ids AS value
            FROM identifiers JOIN ids USING (id)
          UNION
            SELECT id, property, 'REL' AS type, null AS lang, target_id::text AS value
            FROM relations r JOIN ids USING (id)
;
$$;


ALTER FUNCTION public.get_neighbors_metadata(res_id bigint, rel_prop text) OWNER TO zozlak;

--
-- Name: get_relatives_metadata(bigint, text, integer); Type: FUNCTION; Schema: public; Owner: zozlak
--

CREATE FUNCTION public.get_relatives_metadata(res_id bigint, rel_prop text, max_depth integer DEFAULT 999999) RETURNS SETOF public.metadata_view
    LANGUAGE sql
    AS $$
with recursive ids(id, n) as (
  select res_id as id, 0
  union
  select
    case r.target_id when ids.id then r.id else r.target_id end as id,
    case r.target_id when ids.id then ids.n + 1 else ids.n - 1 end as n
  from relations r join ids on (ids.n >= 0 and r.target_id = ids.id) or (ids.n <=0 and r.id = ids.id)
  where property = rel_prop and abs(ids.n) < max_depth
)
            SELECT id, property, type, lang, value
            FROM metadata JOIN ids USING (id)
          UNION
            SELECT id, null AS property, 'ID' AS type, null AS lang, ids AS value
            FROM identifiers JOIN ids USING (id)
          UNION
            SELECT id, property, 'REL' AS type, null AS lang, target_id::text AS value
            FROM relations r JOIN ids USING (id)
;
$$;


ALTER FUNCTION public.get_relatives_metadata(res_id bigint, rel_prop text, max_depth integer) OWNER TO zozlak;

--
-- Name: get_resource_roles(text, text); Type: FUNCTION; Schema: public; Owner: zozlak
--

CREATE FUNCTION public.get_resource_roles(read_prop text, write_prop text) RETURNS TABLE(id bigint, role text, privilege text)
    LANGUAGE sql STABLE
    AS $$
select id, value, 'read' 
from resources join metadata using (id) 
where property = read_prop 
  union
select id, value, 'read' 
from resources join metadata using (id) 
where property = write_prop
;
$$;


ALTER FUNCTION public.get_resource_roles(read_prop text, write_prop text) OWNER TO zozlak;

--
-- Name: ftsid_seq; Type: SEQUENCE; Schema: public; Owner: zozlak
--

CREATE SEQUENCE public.ftsid_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.ftsid_seq OWNER TO zozlak;

--
-- Name: full_text_search; Type: TABLE; Schema: public; Owner: zozlak
--

CREATE TABLE public.full_text_search (
    ftsid bigint DEFAULT nextval('public.ftsid_seq'::regclass) NOT NULL,
    id bigint NOT NULL,
    property text NOT NULL,
    segments tsvector NOT NULL,
    raw text
);


ALTER TABLE public.full_text_search OWNER TO zozlak;

--
-- Name: id_seq; Type: SEQUENCE; Schema: public; Owner: zozlak
--

CREATE SEQUENCE public.id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.id_seq OWNER TO zozlak;

--
-- Name: midh_seq; Type: SEQUENCE; Schema: public; Owner: zozlak
--

CREATE SEQUENCE public.midh_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.midh_seq OWNER TO zozlak;

--
-- Name: metadata_history; Type: TABLE; Schema: public; Owner: zozlak
--

CREATE TABLE public.metadata_history (
    midh bigint DEFAULT nextval('public.midh_seq'::regclass) NOT NULL,
    date timestamp without time zone DEFAULT now() NOT NULL,
    id bigint NOT NULL,
    property text NOT NULL,
    type text NOT NULL,
    lang text NOT NULL,
    value text NOT NULL
);


ALTER TABLE public.metadata_history OWNER TO zozlak;

--
-- Name: raw; Type: TABLE; Schema: public; Owner: zozlak
--

CREATE TABLE public.raw (
    id integer,
    prop text,
    val text,
    type text,
    lang text
);


ALTER TABLE public.raw OWNER TO zozlak;

--
-- Name: resources; Type: TABLE; Schema: public; Owner: zozlak
--

CREATE TABLE public.resources (
    id bigint DEFAULT nextval('public.id_seq'::regclass) NOT NULL,
    transaction_id bigint,
    state text DEFAULT 'active'::text NOT NULL,
    CONSTRAINT resources_state_check CHECK ((state = ANY (ARRAY['active'::text, 'tombstone'::text, 'deleted'::text])))
);


ALTER TABLE public.resources OWNER TO zozlak;

--
-- Name: transactions; Type: TABLE; Schema: public; Owner: zozlak
--

CREATE TABLE public.transactions (
    transaction_id bigint NOT NULL,
    started timestamp without time zone DEFAULT now() NOT NULL,
    last_request timestamp without time zone DEFAULT now() NOT NULL,
    state text DEFAULT 'active'::text NOT NULL,
    CONSTRAINT transactions_state_check CHECK ((state = ANY (ARRAY['active'::text, 'commit'::text, 'rollback'::text])))
);


ALTER TABLE public.transactions OWNER TO zozlak;

--
-- Name: users; Type: TABLE; Schema: public; Owner: zozlak
--

CREATE TABLE public.users (
    user_id text NOT NULL,
    data text
);


ALTER TABLE public.users OWNER TO zozlak;

--
-- Name: full_text_search full_text_search_pkey; Type: CONSTRAINT; Schema: public; Owner: zozlak
--

ALTER TABLE ONLY public.full_text_search
    ADD CONSTRAINT full_text_search_pkey PRIMARY KEY (ftsid);


--
-- Name: identifiers identifiers_pkey; Type: CONSTRAINT; Schema: public; Owner: zozlak
--

ALTER TABLE ONLY public.identifiers
    ADD CONSTRAINT identifiers_pkey PRIMARY KEY (ids);


--
-- Name: metadata_history metadata_history_pkey; Type: CONSTRAINT; Schema: public; Owner: zozlak
--

ALTER TABLE ONLY public.metadata_history
    ADD CONSTRAINT metadata_history_pkey PRIMARY KEY (midh);


--
-- Name: metadata metadata_pkey; Type: CONSTRAINT; Schema: public; Owner: zozlak
--

ALTER TABLE ONLY public.metadata
    ADD CONSTRAINT metadata_pkey PRIMARY KEY (mid);


--
-- Name: relations relations2_pkey; Type: CONSTRAINT; Schema: public; Owner: zozlak
--

ALTER TABLE ONLY public.relations
    ADD CONSTRAINT relations2_pkey PRIMARY KEY (id, target_id, property);


--
-- Name: resources resources_pkey; Type: CONSTRAINT; Schema: public; Owner: zozlak
--

ALTER TABLE ONLY public.resources
    ADD CONSTRAINT resources_pkey PRIMARY KEY (id);


--
-- Name: transactions transactions_pkey; Type: CONSTRAINT; Schema: public; Owner: zozlak
--

ALTER TABLE ONLY public.transactions
    ADD CONSTRAINT transactions_pkey PRIMARY KEY (transaction_id);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: zozlak
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (user_id);


--
-- Name: full_text_search_text_index; Type: INDEX; Schema: public; Owner: zozlak
--

CREATE INDEX full_text_search_text_index ON public.full_text_search USING gin (segments);


--
-- Name: identifiers_id_index; Type: INDEX; Schema: public; Owner: zozlak
--

CREATE INDEX identifiers_id_index ON public.identifiers USING btree (id);


--
-- Name: metadata_id_index; Type: INDEX; Schema: public; Owner: zozlak
--

CREATE INDEX metadata_id_index ON public.metadata USING btree (id);


--
-- Name: metadata_property_index; Type: INDEX; Schema: public; Owner: zozlak
--

CREATE INDEX metadata_property_index ON public.metadata USING btree (property);


--
-- Name: metadata_value_index; Type: INDEX; Schema: public; Owner: zozlak
--

CREATE INDEX metadata_value_index ON public.metadata USING btree ("substring"(value, 1, 1000));


--
-- Name: metadata_value_n_index; Type: INDEX; Schema: public; Owner: zozlak
--

CREATE INDEX metadata_value_n_index ON public.metadata USING btree (value_n);


--
-- Name: metadata_value_t_index; Type: INDEX; Schema: public; Owner: zozlak
--

CREATE INDEX metadata_value_t_index ON public.metadata USING btree (value_t);


--
-- Name: relations2_property_index; Type: INDEX; Schema: public; Owner: zozlak
--

CREATE INDEX relations2_property_index ON public.relations USING btree (property);


--
-- Name: relations2_target_id_index; Type: INDEX; Schema: public; Owner: zozlak
--

CREATE INDEX relations2_target_id_index ON public.relations USING btree (target_id);


--
-- Name: full_text_search full_text_search_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: zozlak
--

ALTER TABLE ONLY public.full_text_search
    ADD CONSTRAINT full_text_search_id_fkey FOREIGN KEY (id) REFERENCES public.resources(id) ON UPDATE CASCADE ON DELETE CASCADE DEFERRABLE INITIALLY DEFERRED;


--
-- Name: identifiers identifiers_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: zozlak
--

ALTER TABLE ONLY public.identifiers
    ADD CONSTRAINT identifiers_id_fkey FOREIGN KEY (id) REFERENCES public.resources(id) ON UPDATE CASCADE ON DELETE CASCADE DEFERRABLE INITIALLY DEFERRED;


--
-- Name: metadata_history metadata_history_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: zozlak
--

ALTER TABLE ONLY public.metadata_history
    ADD CONSTRAINT metadata_history_id_fkey FOREIGN KEY (id) REFERENCES public.resources(id) ON UPDATE CASCADE ON DELETE CASCADE DEFERRABLE INITIALLY DEFERRED;


--
-- Name: metadata metadata_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: zozlak
--

ALTER TABLE ONLY public.metadata
    ADD CONSTRAINT metadata_id_fkey FOREIGN KEY (id) REFERENCES public.resources(id) ON UPDATE CASCADE ON DELETE CASCADE DEFERRABLE INITIALLY DEFERRED;


--
-- Name: relations relations2_target_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: zozlak
--

ALTER TABLE ONLY public.relations
    ADD CONSTRAINT relations2_target_id_fkey FOREIGN KEY (target_id) REFERENCES public.resources(id);


--
-- Name: relations relations_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: zozlak
--

ALTER TABLE ONLY public.relations
    ADD CONSTRAINT relations_id_fkey FOREIGN KEY (id) REFERENCES public.resources(id) ON UPDATE CASCADE ON DELETE CASCADE DEFERRABLE INITIALLY DEFERRED;


--
-- Name: resources resources_transaction_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: zozlak
--

ALTER TABLE ONLY public.resources
    ADD CONSTRAINT resources_transaction_id_fkey FOREIGN KEY (transaction_id) REFERENCES public.transactions(transaction_id) ON UPDATE CASCADE DEFERRABLE INITIALLY DEFERRED;


--
-- PostgreSQL database dump complete
--

COMMIT;

