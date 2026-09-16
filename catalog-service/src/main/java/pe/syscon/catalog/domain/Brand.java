package pe.syscon.catalog.domain;
import jakarta.persistence.*;
import java.util.UUID;
@Entity public class Brand {
 @Id @GeneratedValue(strategy=GenerationType.UUID) public UUID id;
 @Column(nullable=false,unique=true) public String name;
 protected Brand() {} public Brand(String name) {this.name=name;}
}
