library(readr)
library(dplyr)
setwd("~/roboty/ACDH/repo/rdbms/dump/")
d = read_csv('dump.csv', col_names = c('res', 'prop', 'val', 'type', 'lang'), col_types = 'ccccc')
d = d %>%
  filter(!prop %in% c('http://www.iana.org/assignments/relation/describedby', 'http://www.w3.org/ns/auth/acl#accessControl', 'http://fedora.info/definitions/v4/repository#hasFixityService')) %>%
  filter(!res %in% c('https://arche.acdh.oeaw.ac.at/rest/', 'https://arche.acdh.oeaw.ac.at/rest/doorkeeper')) %>%
  filter(!grepl('^https://arche.acdh.oeaw.ac.at/rest/acl', res)) %>%
  filter(!(prop == 'http://fedora.info/definitions/v4/repository#hasParent' & val == 'https://arche.acdh.oeaw.ac.at/rest/'))
id = d %>%
  select(res) %>%
  unique() %>%
  mutate(id = row_number())
tmp = d %>%
  filter(prop == 'http://fedora.info/definitions/v4/repository#hasParent') %>%
  inner_join(id %>% rename(val = res))
d = d %>%
  mutate(
    type = if_else(prop %in% c('http://www.loc.gov/premis/rdf/v1#hasMessageDigest', 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type'), 'http://www.w3.org/2001/XMLSchema#string', type),
    prop = if_else(prop == 'http://fedora.info/definitions/v4/repository#hasParent', 'https://vocabs.acdh.oeaw.ac.at/schema#isPartOf', prop)
  )
TODO - zrobić użytek z tmp i poprawić val na uuid-y
d = id %>%
  inner_join(d) %>%
  mutate(type = coalesce(type, 'http://www.w3.org/2001/XMLSchema#string'))
write_csv(d %>% select(id, prop, val, type, lang), 'raw.csv', na = '')

\copy raw from raw.csv csv header
insert into resources select distinct id from raw;
select setval('id_seq', (select max(id) from resources));
insert into identifiers select val, id from raw where prop = 'https://vocabs.acdh.oeaw.ac.at/schema#hasIdentifier' and type = 'URI';
insert into identifiers select distinct 'https://arche.acdh.oeaw.ac.at/' || id::text, id from raw;
create temporary table missids as select (select max(id) from resources) + row_number() over () as id, val as ids from (select distinct val from raw r where r.type = 'URI' and not exists (select 1 from identifiers i where r.val = i.ids) order by 1) t;
insert into resources select id from missids;
insert into identifiers select ids, id from missids;
drop table missids;
insert into relations select distinct r.id, i.id, r.prop from raw r join identifiers i on r.val = i.ids where r.type = 'URI' and r.property <> 'https://vocabs.acdh.oeaw.ac.at/schema#hasIdentifier';
insert into metadata select row_number() over (), id, prop, type, coalesce(lang, ''),
  case type when 'http://www.w3.org/2001/XMLSchema#integer' then val::double precision when 'http://www.w3.org/2001/XMLSchema#long' then val::double precision when 'http://www.w3.org/2001/XMLSchema#decimal' then val::double precision when 'http://www.w3.org/2001/XMLSchema#float' then val::double precision when 'http://www.w3.org/2001/XMLSchema#double' then val::double precision else null end,
  case type when 'http://www.w3.org/2001/XMLSchema#date' then val::timestamp when 'http://www.w3.org/2001/XMLSchema#dateTime' then val::timestamp else null end,
  val
  from raw r where type <> 'URI';
select setval('mid_seq', (select max(mid) from metadata));
#
# All Troesmis children:
with recursive t(id, n) as (
  select id, 0 as n from identifiers where ids = 'https://id.acdh.oeaw.ac.at/Troesmis'
  union
  select r.id, t.n + 1 from relations r join t on r.target_id = t.id where property = 'https://vocabs.acdh.oeaw.ac.at/schema#isPartOf'
)
select n, id, jsonb_object_agg(property, d) from (select n, id, property, jsonb_object_agg(lang, d) as d from (select n, id, property, lang, array_agg(coalesce(value, textraw)) as d from t join metadata using(id) group by 1, 2, 3, 4) t1 group by 1, 2, 3) t2 group by 1, 2;

with recursive t(id, n) as (
  select id, 0 as n from identifiers where ids = 'https://id.acdh.oeaw.ac.at/Troesmis'
  union
  select r.id, t.n + 1 from relations r join t on r.target_id = t.id where property = 'https://vocabs.acdh.oeaw.ac.at/schema#isPartOf'
)
select * from t join metadata_view using (id);


8317 - troesmis
8655 - mały

