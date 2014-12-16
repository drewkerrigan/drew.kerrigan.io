---
layout: post
title: Riak Data Modeling - Shopping Cart
permalink: "riak-data-modeling-shopping-cart"
author: 
    name: "Drew Kerrigan"
---

Modeling shopping cart data in Riak

# Riak Data Modeling: Shopping Cart

## What is it?

The goal of this post is to describe a few data structures and techniques for creating an online shopping cart using Riak as a data store. There are three main pieces of a basic ecommerce data model:

1. Product Data
2. Inventory Data
3. Shopping Cart Data

### Product Data

#### Sample Object

```
{
  "sku": "abc123",
  "name": "Awesome T-Shirt",
  "categories": ["clothing/mens/shirts", "clothing/womens/shirts"],
  "inventory": ["14418796", "14418797"]
}
```

#### Bucket Configuration

The product data needs to be searchable by users in general, but we also need to build product navigation using this data. For instance we would like to get a listing of all top level categories, and then drill down into sub-categories. Due to these requirements, the Riak bucket / bucket type should be configured with Riak Search. Additionally, we should use Riak Datatypes to ease concerns around multiple actors changing product data.

##### Create Schema

(Based on default Yokozuna Solr Schema with our own field definitions, the default schema can be found here: [https://raw.githubusercontent.com/basho/yokozuna/develop/priv/default_schema.xml](https://raw.githubusercontent.com/basho/yokozuna/develop/priv/default_schema.xml))

Create a `product_schema.xml` based on the default schema:

```
...
<field name="sku_register" type="string" indexed="true" stored="true" multiValued="false"/>
<field name="name_register" type="string" indexed="true" stored="true"  multiValued="false"/>
<field name="categories_set" type="string" indexed="true" stored="true" multiValued="true"/>
...
```

Note that all map values (such as strings, integers, dates, etc) are named as _register when they get to Solr. All registers can only be represented as strings, so in order to make Solr treat them as something else (like an integer), they need to have their own fields in a custom schema.

```
curl -XPUT "http://localhost:8098/search/schema/product_schema" \
  -H 'content-type:application/xml' \
  --data-binary @product_schema.xml
```

##### Create a search index using your schema

```
curl -XPUT "http://localhost:8098/search/index/product_index" \
     -H'content-type:application/json' \
     -d'{"schema":"product_schema"}'
```

##### Create a bucket type so that multiple buckets can share an index as well as be treated as CRDT Maps

```
riak-admin bucket-type create product_type '{"props":{"search_index":"product_index","datatype":"map"}}'
riak-admin bucket-type activate product_type
```

##### Add data

```
curl -XPOST http://localhost:8098/types/product_type/buckets/products/datatypes/product1 \
  -H "Content-Type: application/json" \
  -d '
  {
    "update": {
      "sku_register": "abc123",
      "name_register": "Awesome T-Shirt",
      "categories_set": {
        "add_all": ["clothing/mens/shirts", "clothing/womens/shirts"]
      },
      "inventory_set": {
        "add_all": ["14418796", "14418797"]
      }
    }
  }'
```

##### Search for data

```
curl 'http://localhost:8098/search/query/product_index?wt=json&q=sku_register:abc123'
```

#### Product Navigation

To create a list of categories and sub-categories for navigation, solr facet queries can be used against the indexed product data. Most solr queries, including category listing, can and should be cached in Riak. Caching these results will reduce load on the cluster and promote better overall throughput.

It is also possible to allow for full text search over all products, but doing so could impact cluster performance if the functionality is not severly limited. One approach to solving this problem might be to pre-cache results for common key words and auto-suggest those keywords to the user as they type a query.

Example solr facet query to build category navigation:

```
TODO: facet query here
```

### Inventory Data

#### Sample Object

```
{
  "id": "14418796",
  "sku": "abc123",
  "color": "Black",
  "size": "S",
  "returnable": false,
  "price": 32.00,
  "remaining": 12
}
```

#### Bucket Configuration

The primary differentiating requirement for inventory data is that the amount remaining needs to be accurate. Depending on the actual online-store use-case specifics, there are a variety of ways to tackle this problem. 

If the store is selling something such as limited seats to a concert, a "leasing" mechanism may be required. Riak currently does not support time-based leasing, so a practical approach might be to combine Riak with something that does such as Apache Zookeeper.

If the store's revenue will not be severly impacted when a user can add something to a cart but then find out that the last item has been sold to someone else, Riak alone can be used. We will focus on this configuration for this guide. A strongly consistent bucket is suggested for this type of data as well as serialized writes to ensure a positive user experience when modifying inventory data. Inventory count and attributes can actually be segregated into separate buckets but that is an optimization for a later time.

##### Create a bucket type

```
riak-admin bucket-type create inventory_type '{"props":{"consistent":true}}'
riak-admin bucket-type status inventory_type # Wait until this displays "inventory_type has been created and may be activated"
riak-admin bucket-type activate inventory_type
```

##### Add data

When creating new entries in a strongly consistent bucket, no context needs to be provided.

```
curl -XPOST http://localhost:8098/types/inventory_type/buckets/inventory/keys/14418796 \
  -H "Content-Type: application/json" \
  -d '
  {
    "id": "14418796",
    "sku": "abc123",
    "color": "Black",
    "size": "S",
    "returnable": false,
    "price": 32.00,
    "remaining": 12
  }'
```

##### Get data

Getting data out of a strongly consistent bucket should return a "context" value. All updates to the object in the future should use the current context value, otherwise the write will fail. The typical workflow would be: 

1. Read the object or do a head to find the current context
2. Modify the object locally
3. Put the data back to the same key using the context from step 1

```
curl 'http://localhost:8098/types/inventory_type/buckets/inventory/keys/14418796'
```

### Shopping Cart Data

Shopping cart objects are fairly simple; They are essentially just a set of inventory ids and quantities. The only tricky part is expiring the data since shopping carts are typically transient, especially if a shopping cart is created by an anonymous user. To facilitate easy editing of the cart contents by users or administrators, the suggested approach is using Riak Datatypes with a Set type for the list of cart items.

#### Sample Object

```
{
  "user": "drewkerrigan",
  "expire_time": "2009-09-11T08:00:00-07:00",
  "items": [{"id": "14418796","quantity": 1}]
}
```

#### Bucket Configuration

##### Create a bucket type

```
riak-admin bucket-type create cart_type '{"props":{"datatype":"map"}}'
riak-admin bucket-type activate cart_type
```

##### Add data

```
curl -XPOST http://localhost:8098/types/cart_type/buckets/cart/datatypes/drewkerrigan \
  -H "Content-Type: application/json" \
  -d '
  {
    "update": {
      "user_register": "drewkerrigan",
      "expire_time_register": "2009-09-11T08:00:00-07:00",
      "items_set": {
        "add_all": ["{\"id\": \"14418796\",\"quantity\": 1}"]
      }
    }
  }'
```

##### Get data

```
curl 'http://localhost:8098/types/cart_type/buckets/cart/datatypes/drewkerrigan'
```

##### Expiring data

There are multiple ways to expire old cart data. One fairly easy mechanism (assuming a bounded number of shopping carts), is to index the cart bucket with Riak Search and periodically query for all cart objects older than X timestamp on a scheduled basis, and subsequently deleting those objects. Another less impactful method would be to use bitcask expiry [http://docs.basho.com/riak/latest/ops/advanced/backends/bitcask/](http://docs.basho.com/riak/latest/ops/advanced/backends/bitcask/). An additional catch layer should be added in addition to backend exiry however: whenever a user attempts to read their cart object, a check should be done on the expire_time field to see if it is old and needs to be cleared, although this is dependent on the actual use-case.