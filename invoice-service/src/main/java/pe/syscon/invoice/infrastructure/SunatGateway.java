package pe.syscon.invoice.infrastructure;

import java.io.*;
import java.net.URI;
import java.net.http.*;
import java.nio.file.*;
import java.security.*;
import java.security.cert.X509Certificate;
import java.time.Duration;
import java.util.*;
import java.util.zip.*;
import javax.xml.XMLConstants;
import javax.xml.crypto.dsig.*;
import javax.xml.crypto.dsig.dom.DOMSignContext;
import javax.xml.crypto.dsig.keyinfo.*;
import javax.xml.crypto.dsig.spec.*;
import javax.xml.parsers.DocumentBuilderFactory;
import javax.xml.transform.*;
import javax.xml.transform.dom.DOMSource;
import javax.xml.transform.stream.StreamResult;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.stereotype.Component;
import org.w3c.dom.*;

@Component
public class SunatGateway {
 private final String certificatePath,password,ruc,user,solPassword,endpoint;
 public SunatGateway(@Value("${sunat.certificate-path:}") String certificatePath,@Value("${sunat.certificate-password:}") String password,@Value("${sunat.ruc:}") String ruc,@Value("${sunat.sol-user:}") String user,@Value("${sunat.sol-password:}") String solPassword,@Value("${sunat.endpoint:https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService}") String endpoint) {this.certificatePath=certificatePath;this.password=password;this.ruc=ruc;this.user=user;this.solPassword=solPassword;this.endpoint=endpoint;}
 public boolean configured() {return !certificatePath.isBlank()&&!password.isBlank()&&!ruc.isBlank()&&!user.isBlank()&&!solPassword.isBlank();}
 public String sign(String xml) throws Exception {
  if(!configured()) throw new IllegalStateException("SUNAT no configurado");
  KeyStore store=KeyStore.getInstance("PKCS12");try(var in=Files.newInputStream(Path.of(certificatePath))) {store.load(in,password.toCharArray());}
  String alias=store.aliases().nextElement();PrivateKey key=(PrivateKey)store.getKey(alias,password.toCharArray());X509Certificate cert=(X509Certificate)store.getCertificate(alias);
  var factory=DocumentBuilderFactory.newInstance();factory.setNamespaceAware(true);factory.setFeature("http://apache.org/xml/features/disallow-doctype-decl",true);factory.setFeature(XMLConstants.FEATURE_SECURE_PROCESSING,true);
  Document doc=factory.newDocumentBuilder().parse(new ByteArrayInputStream(xml.getBytes(java.nio.charset.StandardCharsets.UTF_8)));
  String extNs="urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2";
  Element extensions=doc.createElementNS(extNs,"ext:UBLExtensions");Element extension=doc.createElementNS(extNs,"ext:UBLExtension");Element content=doc.createElementNS(extNs,"ext:ExtensionContent");
  extension.appendChild(content);extensions.appendChild(extension);doc.getDocumentElement().insertBefore(extensions,doc.getDocumentElement().getFirstChild());
  XMLSignatureFactory f=XMLSignatureFactory.getInstance("DOM");
  var reference=f.newReference("",f.newDigestMethod(DigestMethod.SHA256,null),List.of(f.newTransform(Transform.ENVELOPED,(TransformParameterSpec)null)),null,null);
  var signedInfo=f.newSignedInfo(f.newCanonicalizationMethod(CanonicalizationMethod.INCLUSIVE,(C14NMethodParameterSpec)null),f.newSignatureMethod(SignatureMethod.RSA_SHA256,null),List.of(reference));
  var keyInfo=f.getKeyInfoFactory().newKeyInfo(List.of(f.getKeyInfoFactory().newX509Data(List.of(cert))));
  f.newXMLSignature(signedInfo,keyInfo).sign(new DOMSignContext(key,content));
  Transformer transformer=TransformerFactory.newInstance().newTransformer();var writer=new StringWriter();transformer.transform(new DOMSource(doc),new StreamResult(writer));return writer.toString();
 }
 public String sendBill(String filename,String signedXml) throws Exception {
  var bytes=new ByteArrayOutputStream();try(var zip=new ZipOutputStream(bytes)) {zip.putNextEntry(new ZipEntry(filename+".xml"));zip.write(signedXml.getBytes(java.nio.charset.StandardCharsets.UTF_8));zip.closeEntry();}
  String username=ruc+user;String payload="<soapenv:Envelope xmlns:soapenv=\"http://schemas.xmlsoap.org/soap/envelope/\" xmlns:ser=\"http://service.sunat.gob.pe\"><soapenv:Header><wsse:Security xmlns:wsse=\"http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd\"><wsse:UsernameToken><wsse:Username>"+escape(username)+"</wsse:Username><wsse:Password>"+escape(solPassword)+"</wsse:Password></wsse:UsernameToken></wsse:Security></soapenv:Header><soapenv:Body><ser:sendBill><fileName>"+escape(filename)+".zip</fileName><contentFile>"+Base64.getEncoder().encodeToString(bytes.toByteArray())+"</contentFile></ser:sendBill></soapenv:Body></soapenv:Envelope>";
  var request=HttpRequest.newBuilder(URI.create(endpoint)).timeout(Duration.ofSeconds(30)).header("Content-Type","text/xml; charset=utf-8").POST(HttpRequest.BodyPublishers.ofString(payload)).build();
  var response=HttpClient.newHttpClient().send(request,HttpResponse.BodyHandlers.ofString());if(response.statusCode()!=200) throw new IOException("SUNAT HTTP "+response.statusCode());
  var factory=DocumentBuilderFactory.newInstance();factory.setNamespaceAware(true);factory.setFeature("http://apache.org/xml/features/disallow-doctype-decl",true);factory.setFeature(XMLConstants.FEATURE_SECURE_PROCESSING,true);
  var document=factory.newDocumentBuilder().parse(new ByteArrayInputStream(response.body().getBytes(java.nio.charset.StandardCharsets.UTF_8)));
  var result=document.getElementsByTagNameNS("*","applicationResponse");if(result.getLength()==0) throw new IOException("Respuesta SUNAT sin CDR: "+response.body().substring(0,Math.min(500,response.body().length())));
  return result.item(0).getTextContent().strip();
 }
 private static String escape(String value) {return value.replace("&","&amp;").replace("<","&lt;").replace(">","&gt;").replace("\"","&quot;");}
}
