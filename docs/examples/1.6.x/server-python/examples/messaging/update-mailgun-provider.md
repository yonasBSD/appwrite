from appwrite.client import Client

client = Client()
client.set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('<YOUR_PROJECT_ID>') # Your project ID
client.set_key('<YOUR_API_KEY>') # Your secret API key

messaging = Messaging(client)

result = messaging.update_mailgun_provider(
    provider_id = '<PROVIDER_ID>',
    name = '<NAME>', # optional
    api_key = '<API_KEY>', # optional
    domain = '<DOMAIN>', # optional
    is_eu_region = False, # optional
    enabled = False, # optional
    from_name = '<FROM_NAME>', # optional
    from_email = 'email@example.com', # optional
    reply_to_name = '<REPLY_TO_NAME>', # optional
    reply_to_email = '<REPLY_TO_EMAIL>' # optional
)