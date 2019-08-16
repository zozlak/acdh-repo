library(readr)
library(dplyr)
setwd("~/roboty/ACDH/repo/rdbms/dump/")
baseUrl = 'http://127.0.0.1/rest/'
dd = read_csv('dump.csv', col_names = c('res', 'prop', 'val', 'type', 'lang'), col_types = 'ccccc')
d = dd %>%
  filter(!prop %in% c('http://www.iana.org/assignments/relation/describedby', 'http://www.w3.org/ns/auth/acl#accessControl', 'http://fedora.info/definitions/v4/repository#hasFixityService')) %>%
  filter(!grepl('^https://arche.acdh.oeaw.ac.at/rest/acl', res)) %>%
  filter(!(prop == 'http://fedora.info/definitions/v4/repository#hasParent' & val == 'https://arche.acdh.oeaw.ac.at/rest/')) %>%
  filter(!is.na(val) & val != '')
id = d %>%
  select(res) %>%
  unique() %>%
  mutate(id = row_number())
tmp = d %>%
  filter(prop == 'http://fedora.info/definitions/v4/repository#hasParent') %>%
  inner_join(id %>% rename(val = res)) %>%
  mutate(
    prop = 'https://vocabs.acdh.oeaw.ac.at/schema#isPartOf',
    val = paste0(baseUrl, id)
  ) %>%
  select(-id)
d = d %>%
  filter(prop != 'http://fedora.info/definitions/v4/repository#hasParent') %>%
  bind_rows(tmp)
d = d %>%
  mutate(
    type = if_else(prop %in% c('http://www.loc.gov/premis/rdf/v1#hasMessageDigest', 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type', 'https://vocabs.acdh.oeaw.ac.at/schema#matchesProp'), 'LITERAL_URI', type)
  )
d = id %>%
  inner_join(d) %>%
  mutate(type = coalesce(type, 'http://www.w3.org/2001/XMLSchema#string'))
write_csv(d %>% select(id, prop, val, type, lang), 'raw.csv', na = '')
