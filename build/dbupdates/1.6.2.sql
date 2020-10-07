update metadata set value_n = extract(year from value_t) where value_t is not null;
